# AI Assistants Management Page - Feature Specification

## Document Info
- **Date Created**: 2026-02-05
- **Version**: 1.0
- **Status**: Draft - Pending Implementation
- **Related Components**: Extensions, Provider Registry, Voice Routing

---

## 1. Overview

### 1.1 Purpose
Create a dedicated "AI Assistants" management page that allows users to configure, manage, and monitor AI-powered conversational agents that can be assigned to extensions, DIDs, or other routing destinations within the OpBX system.

### 1.2 Goals
- **Decouple AI Assistant configuration from Extensions** - Extensions should reference AI Assistants rather than embedding configuration
- **Centralized AI Assistant management** - Single page to create, edit, and monitor all AI Assistants
- **Protocol-aware UI** - Clearly distinguish between SIP-based and WebSocket-based AI providers
- **Reusable AI Assistants** - One AI Assistant can be assigned to multiple extensions/DIDs
- **Consistent UX** - Follow the same design patterns as Conference Rooms page

### 1.3 Architecture Changes

#### Current State (v1)
```
Extension (type: ai_assistant)
  └── configuration: { provider, phone_number, bot_id, auth_token, ... }
```

#### New State (v2)
```
AI Assistant Resource (new table: ai_assistants)
  └── id, name, provider, protocol, configuration

Extension (type: ai_assistant)
  └── configuration: { ai_assistant_id: 123 }
```

---

## 2. Database Schema

### 2.1 New Table: `ai_assistants`

```sql
CREATE TABLE ai_assistants (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    
    -- Basic Information
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    
    -- Provider Configuration
    provider VARCHAR(100) NOT NULL,           -- Key from ProviderRegistry (e.g., 'vapi', 'deepdub')
    protocol ENUM('sip', 'websocket') NOT NULL,
    
    -- Configuration (JSON)
    configuration JSON NOT NULL,              -- Protocol-specific fields
    
    -- Metadata
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    
    -- Indexes
    INDEX idx_organization_status (organization_id, status),
    INDEX idx_organization_provider (organization_id, provider),
    INDEX idx_organization_protocol (organization_id, protocol),
    INDEX idx_deleted_at (deleted_at),
    
    -- Foreign Keys
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
```

### 2.2 Configuration JSON Structure

#### SIP Provider Configuration
```json
{
  "phone_number": "+1234567890"
}
```

#### WebSocket Provider Configuration (Example: DeepDub)
```json
{
  "bot_id": "7Fn5qL8LCMkENwdrh9bhoW",
  "auth_token": "secret_token_here",
  "session_id": "optional_session",
  "custom_params": {
    "language": "en-US",
    "voice": "emma"
  }
}
```

**Note**: Configuration fields are dynamic based on `ProviderDefinition.config_fields` from the Provider Registry.

### 2.3 Extensions Table Update

**Current**:
```sql
extensions.type = 'ai_assistant'
extensions.configuration = { provider, phone_number, bot_id, ... }
```

**New**:
```sql
extensions.type = 'ai_assistant'
extensions.configuration = { ai_assistant_id: 123 }
```

**Migration Strategy**:
1. Create `ai_assistants` table
2. Migrate existing `ai_assistant` extension configurations to new table
3. Update extensions to reference AI Assistant IDs
4. Deploy new UI

---

## 3. Backend Implementation

### 3.1 Model: `AiAssistant`

**File**: `app/Models/AiAssistant.php`

```php
class AiAssistant extends Model
{
    use HasFactory, SoftDeletes, BelongsToOrganization;
    
    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'status',
        'provider',
        'protocol',
        'configuration',
        'created_by',
        'updated_by',
    ];
    
    protected $casts = [
        'configuration' => 'array',
        'status' => 'string',
        'protocol' => 'string',
    ];
    
    // Relationships
    public function organization() { ... }
    public function creator() { ... }
    public function updater() { ... }
    
    // Scopes
    public function scopeActive($query) { ... }
    public function scopeByProtocol($query, $protocol) { ... }
    public function scopeByProvider($query, $provider) { ... }
    
    // Helper methods
    public function getProviderDefinition(): ?ProviderDefinition { ... }
    public function isWebSocket(): bool { ... }
    public function isSip(): bool { ... }
}
```

### 3.2 API Controller: `AiAssistantController`

**File**: `app/Http/Controllers/Api/AiAssistantController.php`

**Endpoints**:
```
GET    /api/ai-assistants              # List (paginated, filtered, sorted)
POST   /api/ai-assistants              # Create
GET    /api/ai-assistants/{id}         # Get details
PUT    /api/ai-assistants/{id}         # Update
DELETE /api/ai-assistants/{id}         # Soft delete
POST   /api/ai-assistants/{id}/test    # Test connection (optional)
```

### 3.3 Request Validation

**File**: `app/Http/Requests/AiAssistant/StoreAiAssistantRequest.php`

**Validation Rules**:
```php
public function rules(): array
{
    return [
        'name' => 'required|string|max:255',
        'description' => 'nullable|string|max:1000',
        'status' => 'required|in:active,inactive',
        'provider' => 'required|string|exists_in_registry',
        'configuration' => 'required|array',
        // Dynamic validation based on provider config_fields
    ];
}
```

**Custom Validator**: Use `ProviderRegistry` to validate configuration fields dynamically based on selected provider.

### 3.4 Service Layer: `AiAssistantService`

**File**: `app/Services/AiAssistant/AiAssistantService.php`

**Responsibilities**:
- CRUD operations with business logic
- Configuration validation against provider definitions
- Protocol detection from provider registry
- Usage tracking (which extensions use this assistant)

---

## 4. Frontend Implementation

### 4.1 Page Structure

**File**: `frontend/src/pages/AiAssistants.tsx`

**Pattern**: Follow `ConferenceRooms.tsx` design structure

### 4.2 UI Components

#### 4.2.1 Page Header
```
┌─────────────────────────────────────────────────────────────┐
│ AI Assistants                                    [+ Create] │
│ Manage AI-powered conversational agents                     │
└─────────────────────────────────────────────────────────────┘
```

- **Title**: "AI Assistants"
- **Subtitle**: "Manage AI-powered conversational agents for call handling"
- **Primary Action**: "Create AI Assistant" button (visible to owners/admins only)

#### 4.2.2 Filters and Search Bar
```
┌─────────────────────────────────────────────────────────────┐
│ [Search: Name or Provider...]  [Status ▼]  [Protocol ▼]    │
│                                [Provider ▼]  [🔄 Refresh]    │
└─────────────────────────────────────────────────────────────┘
```

**Filters**:
1. **Search** (text input):
   - Searches: name, description, provider
   - Debounced (300ms)
   
2. **Status** (dropdown):
   - Options: All, Active, Inactive
   - Default: All
   
3. **Protocol** (dropdown):
   - Options: All, SIP, WebSocket
   - Default: All
   - Badge icons: 📞 SIP, 🌐 WebSocket
   
4. **Provider** (dropdown):
   - Options: All, [dynamic list from registry]
   - Grouped by protocol
   - Default: All

5. **Refresh** (button):
   - Icon: RefreshCw (spinning during refetch)
   - Tooltip: "Refresh list"

#### 4.2.3 Data Table

**Columns**:

| Column | Type | Sortable | Width | Description |
|--------|------|----------|-------|-------------|
| **Name** | Text + Badge | Yes | 25% | AI Assistant name + Protocol badge |
| **Provider** | Text + Icon | Yes | 20% | Provider name with icon |
| **Status** | Badge | Yes | 10% | Active (green) / Inactive (gray) |
| **Description** | Text | No | 25% | Truncated description with tooltip |
| **Usage** | Count | Yes | 10% | Number of extensions using this |
| **Actions** | Buttons | No | 10% | View, Edit, Delete |

**Table Features**:
- **Pagination**: 25/50/100 items per page
- **Empty State**: Custom empty state with icon and CTA
- **Row Actions** (dropdown menu):
  - View Details (eye icon)
  - Edit (pencil icon) - owners/admins only
  - Delete (trash icon) - owners/admins only, disabled if in use
- **Hover State**: Highlight row on hover
- **Click Row**: Open detail sheet

**Protocol Badge**:
```tsx
// SIP Badge
<Badge variant="secondary" className="text-xs">
  <Phone className="h-3 w-3 mr-1" />
  SIP
</Badge>

// WebSocket Badge
<Badge variant="default" className="text-xs">
  <Wifi className="h-3 w-3 mr-1" />
  WebSocket
</Badge>
```

**Usage Count**:
```tsx
<TooltipProvider>
  <Tooltip>
    <TooltipTrigger>
      <Badge variant="outline">{usageCount}</Badge>
    </TooltipTrigger>
    <TooltipContent>
      Used by {usageCount} extension(s)
    </TooltipContent>
  </Tooltip>
</TooltipProvider>
```

#### 4.2.4 Create/Edit Dialog

**Design Pattern**: Multi-tab dialog similar to Conference Rooms

```
┌─────────────────────────────────────────────────────────────┐
│ Create AI Assistant                                    [×]  │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ Name: [________________________]                             │
│ Description: [______________________________________]        │
│                                                              │
│ Status: [Active ●] ○ Inactive                                │
│                                                              │
│ ┌───────────────────────────────────────────────────────┐   │
│ │ [Basic Info] [Provider Config] [Advanced]            │   │
│ └───────────────────────────────────────────────────────┘   │
│                                                              │
│ ... (tab content) ...                                        │
│                                                              │
├─────────────────────────────────────────────────────────────┤
│                          [Cancel]  [Create AI Assistant]    │
└─────────────────────────────────────────────────────────────┘
```

**Tab 1: Basic Info**
- **Name** (required): Text input
- **Description** (optional): Textarea (max 1000 chars)
- **Status**: Toggle switch (Active/Inactive)

**Tab 2: Provider Configuration**

**Step 1: Provider Selection**
```
┌─────────────────────────────────────────────────────────────┐
│ AI Service Provider *                                        │
│                                                              │
│ [Select Provider ▼]                                          │
│  ├─ 📞 SIP Providers                                         │
│  │  ├─ VAPI                                                  │
│  │  ├─ Retell                                                │
│  │  ├─ Synthflow                                             │
│  │  └─ ... (16 total)                                        │
│  │                                                            │
│  └─ 🌐 WebSocket Providers                                   │
│     ├─ DeepDub                                               │
│     └─ ... (more)                                            │
└─────────────────────────────────────────────────────────────┘
```

**Step 2: Dynamic Configuration Fields**

After provider is selected, show:
1. **Protocol Badge**: Display prominently with icon
2. **Provider Description**: Show provider description from registry
3. **Dynamic Fields**: Render fields based on `ProviderDefinition.config_fields`

**Field Rendering Logic**:
```tsx
provider.config_fields.map(field => {
  switch (field.type) {
    case 'text': return <Input type="text" ... />
    case 'password': return <Input type="password" ... />
    case 'tel': return <Input type="tel" ... />
    case 'url': return <Input type="url" ... />
    case 'number': return <Input type="number" ... />
    case 'email': return <Input type="email" ... />
  }
})
```

**Example: SIP Provider (VAPI)**
```
┌─────────────────────────────────────────────────────────────┐
│ Selected Provider: VAPI                    [📞 SIP]          │
│ "VAPI provides AI phone agents..."                          │
│                                                              │
│ Phone Number *                                               │
│ [+1234567890________________]                                │
│ Enter the phone number where Cloudonix will forward calls   │
│ (E.164 format recommended)                                   │
└─────────────────────────────────────────────────────────────┘
```

**Example: WebSocket Provider (DeepDub)**
```
┌─────────────────────────────────────────────────────────────┐
│ Selected Provider: DeepDub                [🌐 WebSocket]    │
│ "DeepDub provides real-time AI conversations via WebSocket" │
│                                                              │
│ Bot ID *                                                     │
│ [7Fn5qL8LCMkENwdrh9bhoW__]                                   │
│ Your unique bot identifier from DeepDub dashboard           │
│                                                              │
│ Auth Token *                                                 │
│ [●●●●●●●●●●●●●●●●________]  [👁 Show]                        │
│ Authentication token for secure communication                │
│                                                              │
│ Session ID (Optional)                                        │
│ [_________________________]                                  │
│ Optional session identifier for tracking                     │
└─────────────────────────────────────────────────────────────┘
```

**Tab 3: Advanced** (Optional - Future)
- Custom headers for WebSocket connections
- Timeout settings
- Retry logic
- Webhook notifications

**Form Validation**:
- Required fields marked with `*`
- Real-time validation based on field type
- Backend validation errors displayed inline
- Disable submit button until form is valid

**Loading States**:
- Show spinner during provider list fetch
- Disable form during submission
- Show success toast on completion

#### 4.2.5 Detail Sheet (Slide-over)

**Pattern**: Right-side sheet similar to Conference Rooms detail view

```
┌─────────────────────────────────────────────────────────────┐
│ AI Assistant Details                               [×]      │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ [Name Badge]                                   [📞 SIP]      │
│ Main Customer Service Bot                                   │
│ Status: ● Active                                             │
│                                                              │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │
│                                                              │
│ Description                                                  │
│ Handles customer inquiries 24/7 with natural conversation   │
│                                                              │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │
│                                                              │
│ Provider Configuration                                       │
│ Provider:        VAPI                                        │
│ Protocol:        📞 SIP                                      │
│ Phone Number:    +1 (555) 123-4567                           │
│                                                              │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │
│                                                              │
│ Usage                                                        │
│ Used by 3 extension(s):                                      │
│ • Extension 101 (Sales)                                      │
│ • Extension 102 (Support)                                    │
│ • Extension 103 (After Hours)                                │
│                                                              │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━   │
│                                                              │
│ Metadata                                                     │
│ Created:         2026-02-05 10:30 AM by John Admin          │
│ Last Updated:    2026-02-05 2:15 PM by Sarah Manager        │
│                                                              │
│ ─────────────────────────────────────────────────────────── │
│                                                              │
│ [Edit AI Assistant]  [Delete]                                │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

**Sections**:

1. **Header**:
   - Name with icon
   - Protocol badge (prominent)
   - Status indicator
   - Close button

2. **Description**:
   - Full description text
   - Fallback: "No description provided"

3. **Provider Configuration**:
   - Provider name
   - Protocol with badge
   - **Dynamic field display** based on configuration:
     - SIP: Phone Number
     - WebSocket: Bot ID, Auth Token (masked), Session ID, etc.
   - Sensitive fields (tokens, passwords): Masked by default with "Show" button

4. **Usage**:
   - Count of extensions using this AI Assistant
   - List of extension numbers and names (clickable links)
   - Link to "View All Extensions" if more than 5

5. **Metadata**:
   - Created by (user + timestamp)
   - Last updated by (user + timestamp)

6. **Actions** (bottom):
   - **Edit** button (primary, owners/admins only)
   - **Delete** button (destructive, disabled if in use)

**Sensitive Field Display**:
```tsx
<div className="flex items-center gap-2">
  <span className="font-mono">
    {showToken ? config.auth_token : '•'.repeat(16)}
  </span>
  <Button
    variant="ghost"
    size="sm"
    onClick={() => setShowToken(!showToken)}
  >
    {showToken ? <EyeOff /> : <Eye />}
  </Button>
</div>
```

#### 4.2.6 Delete Confirmation Dialog

```
┌─────────────────────────────────────────────────────────────┐
│ ⚠️  Delete AI Assistant?                              [×]   │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ Are you sure you want to delete "Main Customer Service      │
│ Bot"? This action cannot be undone.                          │
│                                                              │
│ [!] This AI Assistant is used by 3 extension(s).            │
│     Deleting it will break call routing for:                │
│     • Extension 101 (Sales)                                  │
│     • Extension 102 (Support)                                │
│     • Extension 103 (After Hours)                            │
│                                                              │
│ Please reassign these extensions before deleting.            │
│                                                              │
├─────────────────────────────────────────────────────────────┤
│                                    [Cancel]  [Delete]       │
└─────────────────────────────────────────────────────────────┘
```

**Delete Logic**:
- Check if AI Assistant is in use (query extensions)
- If in use: Show warning, disable delete button
- If not in use: Allow deletion with confirmation
- Soft delete (set `deleted_at`)

#### 4.2.7 Empty State

**Condition**: No AI Assistants exist (or all filtered out)

```
┌─────────────────────────────────────────────────────────────┐
│                                                              │
│                         [🤖]                                 │
│                                                              │
│                  No AI Assistants found                      │
│                                                              │
│          Get started by creating your first AI Assistant    │
│          to power intelligent call handling                  │
│                                                              │
│                  [+ Create AI Assistant]                     │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

**With Active Filters**:
```
┌─────────────────────────────────────────────────────────────┐
│                                                              │
│                         [🤖]                                 │
│                                                              │
│                  No AI Assistants found                      │
│                                                              │
│              Try adjusting your search or filters            │
│                                                              │
│                      [Clear Filters]                         │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

## 5. Extensions Page Updates

### 5.1 Current Behavior (Remove)
- Extensions page allows inline AI Assistant configuration
- Provider selection and phone number entry embedded in extension form

### 5.2 New Behavior (Replace With)

**Extension Form - AI Assistant Type**:
```
Extension Type: AI Assistant

┌─────────────────────────────────────────────────────────────┐
│ AI Assistant *                                               │
│ [Select AI Assistant ▼]                                      │
│  ├─ Main Customer Service Bot (VAPI - SIP)                  │
│  ├─ After Hours Support (DeepDub - WebSocket)               │
│  └─ ... (all active AI Assistants)                          │
│                                                              │
│ [+ Create New AI Assistant]                                  │
└─────────────────────────────────────────────────────────────┘

Note: AI Assistants are managed separately and can be reused
      across multiple extensions.
```

**Configuration Structure**:
```json
{
  "ai_assistant_id": 123
}
```

**New AI Assistant Creation**:
- "Create New AI Assistant" link opens AI Assistants page in new tab/modal
- After creation, returns to extension form with new AI Assistant pre-selected

### 5.3 Extension Detail View Update

**Current Display**:
```
Configuration
Provider:         VAPI
Phone Number:     +1 (555) 123-4567
```

**New Display**:
```
Configuration
AI Assistant:     Main Customer Service Bot [View →]
Provider:         VAPI (SIP)
Status:           ● Active
```

- "View →" link opens AI Assistant detail sheet
- Shows provider and protocol from linked AI Assistant
- Shows status indicator

### 5.4 Migration Handling

**Backend Migration Job**:
```php
// Migrate existing ai_assistant extensions
Extension::where('type', 'ai_assistant')
    ->whereNotNull('configuration')
    ->chunk(100, function ($extensions) {
        foreach ($extensions as $extension) {
            $config = $extension->configuration;
            
            // Create AI Assistant from extension config
            $assistant = AiAssistant::create([
                'organization_id' => $extension->organization_id,
                'name' => "Extension {$extension->extension_number} AI",
                'provider' => $config['provider'],
                'protocol' => determineProtocol($config),
                'configuration' => extractProviderConfig($config),
                'status' => 'active',
            ]);
            
            // Update extension to reference AI Assistant
            $extension->update([
                'configuration' => ['ai_assistant_id' => $assistant->id]
            ]);
        }
    });
```

---

## 6. Navigation Menu Updates

### 6.1 Add New Menu Item

**Location**: Main sidebar navigation

**Position**: After "Conference Rooms", before "Business Hours"

```
├─ Users
├─ Extensions
├─ Ring Groups
├─ IVR Menus
├─ Conference Rooms
├─ AI Assistants        ← NEW
├─ Business Hours
└─ ...
```

**Menu Item Properties**:
- **Label**: "AI Assistants"
- **Icon**: `Bot` from lucide-react
- **Route**: `/ai-assistants`
- **Permissions**: All roles can view (content is role-filtered)
- **Active State**: Matches `/ai-assistants*` routes

### 6.2 Breadcrumbs

```
Home > AI Assistants
Home > AI Assistants > Create
Home > AI Assistants > [AI Assistant Name]
```

---

## 7. Role-Based Access Control

### 7.1 Permissions Matrix

| Role | View List | View Details | Create | Edit | Delete |
|------|-----------|--------------|--------|------|--------|
| **Owner** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **PBX Admin** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **PBX User** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Reporter** | ✅ | ✅ | ❌ | ❌ | ❌ |

### 7.2 UI Behavior by Role

**PBX User / Reporter**:
- Can view AI Assistants list and details
- Cannot see "Create" button
- Cannot see "Edit" or "Delete" actions
- Read-only mode with informational tooltips

**Owner / PBX Admin**:
- Full CRUD access
- Can create, edit, and delete AI Assistants
- Can test AI Assistant connections (future feature)

---

## 8. API Specifications

### 8.1 List AI Assistants

**Endpoint**: `GET /api/ai-assistants`

**Query Parameters**:
```
page          = 1               # Pagination page
per_page      = 25              # Items per page (25, 50, 100)
search        = "customer"      # Search name, description, provider
status        = "active"        # Filter: all, active, inactive
protocol      = "sip"           # Filter: all, sip, websocket
provider      = "vapi"          # Filter by provider key
sort_by       = "name"          # Sort field: name, created_at, provider
sort_order    = "asc"           # Sort direction: asc, desc
```

**Response**:
```json
{
  "data": [
    {
      "id": 1,
      "organization_id": 5,
      "name": "Main Customer Service Bot",
      "description": "Handles customer inquiries 24/7",
      "status": "active",
      "provider": "vapi",
      "protocol": "sip",
      "configuration": {
        "phone_number": "+15551234567"
      },
      "usage_count": 3,
      "created_by": {
        "id": 10,
        "name": "John Admin"
      },
      "updated_by": {
        "id": 12,
        "name": "Sarah Manager"
      },
      "created_at": "2026-02-05T10:30:00Z",
      "updated_at": "2026-02-05T14:15:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 15,
    "last_page": 1,
    "from": 1,
    "to": 15
  }
}
```

### 8.2 Create AI Assistant

**Endpoint**: `POST /api/ai-assistants`

**Request**:
```json
{
  "name": "Main Customer Service Bot",
  "description": "Handles customer inquiries 24/7",
  "status": "active",
  "provider": "vapi",
  "configuration": {
    "phone_number": "+15551234567"
  }
}
```

**Notes**:
- `protocol` is auto-detected from `provider` via ProviderRegistry
- `configuration` fields are validated dynamically based on provider

**Response**: `201 Created`
```json
{
  "data": {
    "id": 1,
    "organization_id": 5,
    "name": "Main Customer Service Bot",
    "description": "Handles customer inquiries 24/7",
    "status": "active",
    "provider": "vapi",
    "protocol": "sip",
    "configuration": {
      "phone_number": "+15551234567"
    },
    "usage_count": 0,
    "created_at": "2026-02-05T10:30:00Z",
    "updated_at": "2026-02-05T10:30:00Z"
  }
}
```

### 8.3 Get AI Assistant Details

**Endpoint**: `GET /api/ai-assistants/{id}`

**Response**: `200 OK`
```json
{
  "data": {
    "id": 1,
    "organization_id": 5,
    "name": "Main Customer Service Bot",
    "description": "Handles customer inquiries 24/7",
    "status": "active",
    "provider": "vapi",
    "protocol": "sip",
    "configuration": {
      "phone_number": "+15551234567"
    },
    "usage_count": 3,
    "used_by_extensions": [
      {
        "id": 10,
        "extension_number": "101",
        "name": "Sales"
      },
      {
        "id": 11,
        "extension_number": "102",
        "name": "Support"
      }
    ],
    "created_by": {
      "id": 10,
      "name": "John Admin"
    },
    "updated_by": {
      "id": 12,
      "name": "Sarah Manager"
    },
    "created_at": "2026-02-05T10:30:00Z",
    "updated_at": "2026-02-05T14:15:00Z"
  }
}
```

### 8.4 Update AI Assistant

**Endpoint**: `PUT /api/ai-assistants/{id}`

**Request**: Same as Create (partial updates allowed)

**Response**: `200 OK` (same structure as Get)

### 8.5 Delete AI Assistant

**Endpoint**: `DELETE /api/ai-assistants/{id}`

**Validation**:
- Check if AI Assistant is in use by any extensions
- If in use, return `422 Unprocessable Entity`:

```json
{
  "error": {
    "message": "Cannot delete AI Assistant that is in use",
    "code": "AI_ASSISTANT_IN_USE",
    "details": {
      "usage_count": 3,
      "extensions": [101, 102, 103]
    }
  }
}
```

**Response** (if not in use): `204 No Content`

---

## 9. Voice Routing Integration

### 9.1 Routing Strategy Update

**File**: `app/Services/VoiceRouting/Strategies/AiAgentRoutingStrategy.php`

**Current Behavior**:
```php
// Extension has configuration directly
$config = $extension->configuration;
$provider = $config['provider'];
$phoneNumber = $config['phone_number'];
```

**New Behavior**:
```php
// Extension references AI Assistant
$assistantId = $extension->configuration['ai_assistant_id'];
$assistant = AiAssistant::findOrFail($assistantId);

// Use AI Assistant configuration
$provider = $assistant->provider;
$protocol = $assistant->protocol;
$config = $assistant->configuration;

if ($protocol === 'websocket') {
    return $this->routeWebSocket($assistant, $callParams);
} else {
    return $this->routeSip($assistant, $callParams);
}
```

### 9.2 Error Handling

**Scenarios**:
1. **AI Assistant not found**: Return error CXML
2. **AI Assistant inactive**: Return error CXML or fallback
3. **Configuration invalid**: Log error, return fallback

**Fallback CXML** (if AI Assistant unavailable):
```xml
<Response>
  <Say>We're sorry, but the AI assistant is currently unavailable. 
       Please try again later or press 0 for operator assistance.</Say>
  <Dial>
    <Extension>0</Extension>
  </Dial>
</Response>
```

---

## 10. Testing Requirements

### 10.1 Backend Unit Tests

**File**: `tests/Unit/Services/AiAssistant/AiAssistantServiceTest.php`

**Test Cases**:
- ✅ Create AI Assistant with SIP provider
- ✅ Create AI Assistant with WebSocket provider
- ✅ Validate configuration against provider definition
- ✅ Update AI Assistant configuration
- ✅ Prevent deletion when in use
- ✅ Allow deletion when not in use
- ✅ Soft delete and restore
- ✅ Calculate usage count correctly
- ✅ Protocol auto-detection from provider

### 10.2 Backend Integration Tests

**File**: `tests/Feature/Api/AiAssistantControllerTest.php`

**Test Cases**:
- ✅ List AI Assistants with pagination
- ✅ Filter by status, protocol, provider
- ✅ Search by name and description
- ✅ Sort by different fields
- ✅ Create AI Assistant with valid data
- ✅ Reject invalid configuration
- ✅ Update AI Assistant
- ✅ Delete AI Assistant (in use vs. not in use)
- ✅ Tenant isolation (cannot access other org's assistants)
- ✅ RBAC enforcement

### 10.3 Frontend Component Tests

**File**: `frontend/src/pages/AiAssistants.test.tsx`

**Test Cases**:
- ✅ Render empty state
- ✅ Render list with data
- ✅ Search filters results
- ✅ Status filter works
- ✅ Protocol filter works
- ✅ Create dialog opens and submits
- ✅ Edit dialog pre-fills data
- ✅ Delete confirmation shows usage warning
- ✅ Detail sheet displays correctly
- ✅ Protocol badge shows correct icon
- ✅ Sensitive fields are masked

### 10.4 E2E Tests (Cypress/Playwright)

**File**: `tests/e2e/ai-assistants.spec.ts`

**Test Scenarios**:
1. **Create SIP-based AI Assistant**
   - Navigate to AI Assistants page
   - Click "Create AI Assistant"
   - Fill in name and description
   - Select SIP provider (VAPI)
   - Enter phone number
   - Submit and verify success

2. **Create WebSocket-based AI Assistant**
   - Navigate to AI Assistants page
   - Click "Create AI Assistant"
   - Fill in name and description
   - Select WebSocket provider (DeepDub)
   - Enter bot_id and auth_token
   - Submit and verify success

3. **Assign AI Assistant to Extension**
   - Navigate to Extensions page
   - Create new extension (type: AI Assistant)
   - Select AI Assistant from dropdown
   - Save and verify

4. **Prevent deletion of in-use AI Assistant**
   - Create AI Assistant
   - Assign to extension
   - Try to delete AI Assistant
   - Verify error message
   - Unassign from extension
   - Delete successfully

---

## 11. Future Enhancements (Out of Scope for v1)

### 11.1 Connection Testing
- Add "Test Connection" button in AI Assistant form
- Validate provider credentials before saving
- Show connection status in list view

### 11.2 Usage Analytics
- Track call volume per AI Assistant
- Show average call duration
- Display success/failure rates
- Export usage reports

### 11.3 Provider Marketplace
- Browse and install new AI providers
- Auto-update provider definitions
- Community-contributed providers

### 11.4 A/B Testing
- Create multiple AI Assistants for same use case
- Randomly distribute calls
- Compare performance metrics

### 11.5 Fallback Chains
- Configure fallback AI Assistant if primary fails
- Cascade to operator if all fail

### 11.6 Custom Webhooks
- Notify external systems on call events
- Trigger workflows based on AI responses

---

## 12. Implementation Timeline

### Phase 1: Backend Foundation (Week 1)
- ✅ Create `ai_assistants` table migration
- ✅ Create `AiAssistant` model with relationships
- ✅ Create `AiAssistantService` with CRUD logic
- ✅ Create validation rules with dynamic provider validation
- ✅ Unit tests for service layer

### Phase 2: Backend API (Week 2)
- ✅ Create `AiAssistantController` with all endpoints
- ✅ Add routes and middleware
- ✅ Integration tests for API
- ✅ Update Extensions API to support AI Assistant references
- ✅ Create migration job for existing data

### Phase 3: Frontend UI (Week 3)
- ✅ Create `AiAssistants.tsx` page
- ✅ Create list view with filters
- ✅ Create Create/Edit dialog with dynamic form
- ✅ Create detail sheet
- ✅ Add navigation menu item
- ✅ Component tests

### Phase 4: Extensions Integration (Week 4)
- ✅ Update Extensions page to use AI Assistant selector
- ✅ Update Extension detail view
- ✅ Update voice routing strategy
- ✅ E2E tests
- ✅ Documentation updates

### Phase 5: Testing & Refinement (Week 5)
- ✅ Full regression testing
- ✅ Performance optimization
- ✅ User acceptance testing
- ✅ Bug fixes
- ✅ Production deployment

---

## 13. Success Criteria

### 13.1 Functional Requirements
- ✅ Users can create SIP-based AI Assistants
- ✅ Users can create WebSocket-based AI Assistants
- ✅ AI Assistants can be assigned to multiple extensions
- ✅ Extensions correctly route to AI Assistants
- ✅ Cannot delete AI Assistants that are in use
- ✅ All filters and search work correctly
- ✅ RBAC is enforced properly

### 13.2 Non-Functional Requirements
- ✅ Page loads in < 2 seconds
- ✅ API responses in < 500ms (p95)
- ✅ Mobile-responsive design
- ✅ WCAG 2.1 AA accessibility compliance
- ✅ 100% unit test coverage for critical paths
- ✅ Zero production bugs in first week

### 13.3 User Experience
- ✅ Users can create AI Assistant in < 2 minutes
- ✅ Protocol distinction is clear and intuitive
- ✅ Error messages are helpful and actionable
- ✅ Consistent with existing OpBX design patterns

---

## 14. Open Questions / Decisions Needed

### 14.1 Provider Registry
- ❓ Should providers be stored in database or remain code-defined?
  - **Recommendation**: Keep in code for v1, database in v2 for dynamic providers

### 14.2 Configuration Encryption
- ❓ Should sensitive fields (auth_token, api_key) be encrypted at rest?
  - **Recommendation**: Yes, use Laravel encryption for sensitive fields

### 14.3 AI Assistant Versioning
- ❓ Should we support versioning of AI Assistant configurations?
  - **Recommendation**: No for v1, add in v2 if needed

### 14.4 Global AI Assistants
- ❓ Should there be "system-level" AI Assistants shared across organizations?
  - **Recommendation**: No, enforce strict tenant isolation

### 14.5 AI Assistant Templates
- ❓ Should we provide pre-configured AI Assistant templates?
  - **Recommendation**: Yes, add in v2 after gathering user feedback

---

## 15. Risks & Mitigations

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| **Breaking existing AI Assistant extensions** | High | Medium | Thorough migration testing, rollback plan |
| **Provider API changes** | Medium | Low | Version provider definitions, validate configs |
| **Performance issues with many AI Assistants** | Medium | Low | Implement caching, optimize queries |
| **User confusion with new workflow** | Low | Medium | Clear documentation, tooltips, onboarding |
| **Security: Exposed tokens in logs** | High | Low | Never log sensitive fields, use masking |

---

## 16. References

### 16.1 Related Documentation
- [Provider Registry Implementation](../backend/provider-registry.md)
- [WebSocket Routing Strategy](../backend/websocket-routing.md)
- [Conference Rooms UI Pattern](../frontend/conference-rooms-pattern.md)
- [Extensions API Specification](../api/extensions.md)

### 16.2 Design Assets
- Figma mockups: [Link TBD]
- Icon assets: Lucide React icons
- Color palette: OpBX design system

### 16.3 External Resources
- Cloudonix CXML Documentation
- Provider-specific API docs (VAPI, Retell, DeepDub, etc.)

---

## Appendix A: Example Provider Definitions

### SIP Provider: VAPI
```json
{
  "key": "vapi",
  "name": "VAPI",
  "description": "VAPI provides AI phone agents with natural conversations",
  "protocol": "sip",
  "config_fields": [
    {
      "key": "phone_number",
      "label": "Phone Number",
      "type": "tel",
      "required": true,
      "placeholder": "+1234567890",
      "description": "The phone number where Cloudonix will forward calls (E.164 format)"
    }
  ]
}
```

### WebSocket Provider: DeepDub
```json
{
  "key": "deepdub",
  "name": "DeepDub",
  "description": "Real-time AI conversations via WebSocket streaming",
  "protocol": "websocket",
  "url_template": "wss://bot.deepdub.dev/ws/{bot_id}/{auth_token}?session={session}&from={from}&to={to}",
  "config_fields": [
    {
      "key": "bot_id",
      "label": "Bot ID",
      "type": "text",
      "required": true,
      "placeholder": "7Fn5qL8LCMkENwdrh9bhoW",
      "description": "Your unique bot identifier from DeepDub dashboard"
    },
    {
      "key": "auth_token",
      "label": "Auth Token",
      "type": "password",
      "required": true,
      "description": "Authentication token for secure communication"
    },
    {
      "key": "session_id",
      "label": "Session ID",
      "type": "text",
      "required": false,
      "description": "Optional session identifier for tracking"
    }
  ]
}
```

---

## Document Change Log

| Date | Version | Author | Changes |
|------|---------|--------|---------|
| 2026-02-05 | 1.0 | John The Great | Initial specification |

---

**Status**: ✅ Ready for Review and Implementation
