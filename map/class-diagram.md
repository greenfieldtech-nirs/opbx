# OpBX Class Diagram

```mermaid
classDiagram
    %% Base Classes and Interfaces
    class Controller {
        +authorizeRequests()
    }

    class Model {
        +fillable[]
        +casts()
        +relationships()
    }

    %% Traits
    class ApiRequestHandler {
        +getRequestId(): string
        +getAuthenticatedUser(): ?object
        +handleRequest(): JsonResponse
    }

    class HandlesWebhookErrors {
        +handleWebhookError(): Response
    }

    %% Core Models (Multi-tenant base)
    class Organization {
        +name: string
        +slug: string
        +status: string
        +timezone: string
        +settings: array
        +users(): HasMany
        +extensions(): HasMany
        +didNumbers(): HasMany
        +ringGroups(): HasMany
        +businessHoursSchedules(): HasMany
        +callLogs(): HasMany
        +cloudonixSettings(): HasOne
        +sentryBlacklists(): HasMany
        +isActive(): bool
    }

    class User {
        +organization_id: bigint
        +name: string
        +email: string
        +password: string
        +role: enum
        +is_active: boolean
        +organization(): BelongsTo
        +extensions(): HasMany
        +extension(): HasOne
    }

    class Extension {
        +organization_id: bigint
        +user_id: bigint
        +extension_number: string
        +password: string
        +type: enum
        +is_active: boolean
        +organization(): BelongsTo
        +user(): BelongsTo
        +ringGroupMembers(): HasMany
    }

    %% Routing Models
    class DidNumber {
        +organization_id: bigint
        +number: string
        +routing_type: enum
        +routing_target_id: bigint
        +organization(): BelongsTo
        +routingExtension(): BelongsTo
        +routingRingGroup(): BelongsTo
        +routingBusinessHours(): BelongsTo
    }

    class RingGroup {
        +organization_id: bigint
        +name: string
        +strategy: enum
        +ring_timeout: integer
        +organization(): BelongsTo
        +members(): HasMany
    }

    class RingGroupMember {
        +ring_group_id: bigint
        +extension_id: bigint
        +priority: integer
        +ringGroup(): BelongsTo
        +extension(): BelongsTo
    }

    class BusinessHours {
        +organization_id: bigint
        +name: string
        +timezone: string
        +is_active: boolean
        +organization(): BelongsTo
        +schedules(): HasMany
    }

    class BusinessHoursSchedule {
        +business_hours_id: bigint
        +day_of_week: integer
        +start_time: time
        +end_time: time
        +businessHours(): BelongsTo
    }

    class IvrMenu {
        +organization_id: bigint
        +name: string
        +greeting_message: text
        +timeout_seconds: integer
        +max_attempts: integer
        +organization(): BelongsTo
        +options(): HasMany
    }

    class IvrMenuOption {
        +ivr_menu_id: bigint
        +digit: string
        +action_type: enum
        +action_target_id: bigint
        +description: string
        +ivrMenu(): BelongsTo
        +targetExtension(): BelongsTo
        +targetRingGroup(): BelongsTo
        +targetBusinessHours(): BelongsTo
    }

    %% Call Data Models
    class CallLog {
        +organization_id: bigint
        +call_id: string
        +direction: enum
        +from_number: string
        +to_number: string
        +start_time: datetime
        +end_time: datetime
        +duration: integer
        +status: enum
    }

    class CallDetailRecord {
        +organization_id: bigint
        +call_id: string
        +session_id: string
        +direction: enum
        +from_number: string
        +to_number: string
        +start_time: datetime
        +answer_time: datetime
        +end_time: datetime
        +duration: integer
        +billable_duration: integer
        +status: string
        +hangup_cause: string
    }

    class SessionUpdate {
        +organization_id: bigint
        +call_id: string
        +session_id: string
        +event_type: string
        +from_state: string
        +to_state: string
        +timestamp: datetime
        +metadata: json
    }

    %% Configuration Models
    class CloudonixSettings {
        +organization_id: bigint
        +domain_uuid: string
        +api_key: string
        +webhook_secret: string
    }

    class ConferenceRoom {
        +organization_id: bigint
        +name: string
        +extension_number: string
        +pin: string
        +max_participants: integer
    }

    %% Controllers
    class UsersController {
        +index(): JsonResponse
        +store(): JsonResponse
        +show(): JsonResponse
        +update(): JsonResponse
        +destroy(): JsonResponse
        +restore(): JsonResponse
    }

    class ExtensionsController {
        +index(): JsonResponse
        +store(): JsonResponse
        +show(): JsonResponse
        +update(): JsonResponse
        +destroy(): JsonResponse
        +regeneratePassword(): JsonResponse
    }

    class RingGroupsController {
        +index(): JsonResponse
        +store(): JsonResponse
        +show(): JsonResponse
        +update(): JsonResponse
        +destroy(): JsonResponse
        +addMember(): JsonResponse
        +removeMember(): JsonResponse
    }

    class BusinessHoursController {
        +index(): JsonResponse
        +store(): JsonResponse
        +show(): JsonResponse
        +update(): JsonResponse
        +destroy(): JsonResponse
    }

    class PhoneNumbersController {
        +index(): JsonResponse
        +store(): JsonResponse
        +show(): JsonResponse
        +update(): JsonResponse
        +destroy(): JsonResponse
    }

    class CallLogController {
        +index(): JsonResponse
        +show(): JsonResponse
    }

    class CallDetailRecordController {
        +index(): JsonResponse
    }

    class ConferenceRoomController {
        +index(): JsonResponse
        +store(): JsonResponse
        +update(): JsonResponse
        +destroy(): JsonResponse
    }

    class IvrMenuController {
        +index(): JsonResponse
        +store(): JsonResponse
        +update(): JsonResponse
        +destroy(): JsonResponse
    }

    class AuthController {
        +login(): JsonResponse
    }

    class ProfileController {
        +show(): JsonResponse
        +update(): JsonResponse
    }

    class SettingsController {
        +show(): JsonResponse
        +update(): JsonResponse
    }

    %% Webhook Controllers
    class CloudonixWebhookController {
        +callInitiated(): Response
        +sessionUpdate(): Response
        +cdr(): Response
    }

    %% Voice Controllers
    class VoiceRoutingController {
        +handleInbound(): Response
        +handleRingGroupCallback(): Response
        +handleIvrInput(): Response
    }

    %% Services Layer
    class CallRoutingService {
        +resolveDidRouting(): RoutingResult
        +getExtensionByNumber(): Extension
        +validateRoutingTarget(): bool
        +getRoutingPriority(): array
    }

    class VoiceRoutingManager {
        +handleInbound(): Response
        +routeToExtension(): Response
        +routeToRingGroup(): Response
        +evaluateBusinessHours(): bool
        +generateCxmlResponse(): string
    }

    class VoiceRoutingCacheService {
        +getCachedExtension(): Extension
        +invalidateExtensionCache(): void
        +warmRoutingCaches(): void
    }

    class IvrStateService {
        +processIvrInput(): IvrResult
        +getCurrentMenu(): IvrMenu
        +advanceToNextMenu(): void
        +handleTimeout(): void
    }

    class IvrMenuService {
        +validateMenuStructure(): bool
        +getMenuOptions(): Collection
        +resolveOptionTarget(): Model
        +generateVoicePrompt(): string
    }

    class ResilientCacheService {
        +get(): mixed
        +set(): bool
        +lock(): Lock
        +remember(): mixed
    }

    class CxmlBuilder {
        +createDialResponse(): string
        +createRingGroupResponse(): string
        +createIvrResponse(): string
        +createConferenceResponse(): string
    }

    class RoutingSentryService {
        +validateOrganizationAccess(): bool
        +checkRoutingPermissions(): bool
        +auditRoutingDecision(): void
    }

    %% Strategy Pattern
    class RoutingStrategy {
        <<interface>>
        +canHandle(): bool
        +route(): Response
    }

    class UserRoutingStrategy {
        +canHandle(): bool
        +route(): Response
    }

    class RingGroupRoutingStrategy {
        +canHandle(): bool
        +route(): Response
    }

    class ConferenceRoutingStrategy {
        +canHandle(): bool
        +route(): Response
    }

    class IvrRoutingStrategy {
        +canHandle(): bool
        +route(): Response
    }

    class QueueRoutingStrategy {
        +canHandle(): bool
        +route(): Response
    }

    class ForwardRoutingStrategy {
        +canHandle(): bool
        +route(): Response
    }

    class AiAgentRoutingStrategy {
        +canHandle(): bool
        +route(): Response
    }

    %% Relationships
    Controller <|-- UsersController
    Controller <|-- ExtensionsController
    Controller <|-- RingGroupsController
    Controller <|-- BusinessHoursController
    Controller <|-- PhoneNumbersController
    Controller <|-- CallLogController
    Controller <|-- CallDetailRecordController
    Controller <|-- ConferenceRoomController
    Controller <|-- IvrMenuController
    Controller <|-- AuthController
    Controller <|-- ProfileController
    Controller <|-- SettingsController
    Controller <|-- CloudonixWebhookController
    Controller <|-- VoiceRoutingController

    UsersController ..|> ApiRequestHandler : uses
    ExtensionsController ..|> ApiRequestHandler : uses
    RingGroupsController ..|> ApiRequestHandler : uses
    BusinessHoursController ..|> ApiRequestHandler : uses
    PhoneNumbersController ..|> ApiRequestHandler : uses
    CallLogController ..|> ApiRequestHandler : uses
    CallDetailRecordController ..|> ApiRequestHandler : uses
    ConferenceRoomController ..|> ApiRequestHandler : uses
    IvrMenuController ..|> ApiRequestHandler : uses
    ProfileController ..|> ApiRequestHandler : uses
    SettingsController ..|> ApiRequestHandler : uses

    CloudonixWebhookController ..|> HandlesWebhookErrors : uses

    Model <|-- Organization
    Model <|-- User
    Model <|-- Extension
    Model <|-- DidNumber
    Model <|-- RingGroup
    Model <|-- RingGroupMember
    Model <|-- BusinessHours
    Model <|-- BusinessHoursSchedule
    Model <|-- IvrMenu
    Model <|-- IvrMenuOption
    Model <|-- CallLog
    Model <|-- CallDetailRecord
    Model <|-- SessionUpdate
    Model <|-- CloudonixSettings
    Model <|-- ConferenceRoom

    Organization ||--o{ User : hasMany
    Organization ||--o{ Extension : hasMany
    Organization ||--o{ DidNumber : hasMany
    Organization ||--o{ RingGroup : hasMany
    Organization ||--o{ BusinessHours : hasMany
    Organization ||--o{ IvrMenu : hasMany
    Organization ||--o{ CallLog : hasMany
    Organization ||--o{ CallDetailRecord : hasMany
    Organization ||--o{ SessionUpdate : hasMany
    Organization ||--o{ ConferenceRoom : hasMany
    Organization ||--|| CloudonixSettings : hasOne

    User ||--o{ Extension : hasMany
    User ||--|| Extension : hasOne

    RingGroup ||--o{ RingGroupMember : hasMany
    RingGroupMember ||--|| Extension : belongsTo
    RingGroupMember ||--|| RingGroup : belongsTo

    BusinessHours ||--o{ BusinessHoursSchedule : hasMany
    BusinessHoursSchedule ||--|| BusinessHours : belongsTo

    IvrMenu ||--o{ IvrMenuOption : hasMany
    IvrMenuOption ||--|| IvrMenu : belongsTo

    DidNumber ..> Extension : polymorphic
    DidNumber ..> RingGroup : polymorphic
    DidNumber ..> BusinessHours : polymorphic
    IvrMenuOption ..> Extension : polymorphic
    IvrMenuOption ..> RingGroup : polymorphic
    IvrMenuOption ..> BusinessHours : polymorphic

    VoiceRoutingManager --> RoutingSentryService : uses
    VoiceRoutingManager --> VoiceRoutingCacheService : uses
    VoiceRoutingManager --> IvrStateService : uses
    VoiceRoutingManager --> CxmlBuilder : uses
    VoiceRoutingManager --> RoutingStrategy : uses

    VoiceRoutingController --> VoiceRoutingManager : uses
    CloudonixWebhookController --> CallRoutingService : uses
    CloudonixWebhookController --> CxmlBuilder : uses
    CloudonixWebhookController --> ResilientCacheService : uses

    RoutingStrategy <|.. UserRoutingStrategy : implements
    RoutingStrategy <|.. RingGroupRoutingStrategy : implements
    RoutingStrategy <|.. ConferenceRoutingStrategy : implements
    RoutingStrategy <|.. IvrRoutingStrategy : implements
    RoutingStrategy <|.. QueueRoutingStrategy : implements
    RoutingStrategy <|.. ForwardRoutingStrategy : implements
    RoutingStrategy <|.. AiAgentRoutingStrategy : implements

    CallRoutingService --> ResilientCacheService : uses
    IvrRoutingStrategy --> IvrStateService : uses
    IvrRoutingStrategy --> IvrMenuService : uses

    VoiceRoutingManager ..> Extension : uses
    VoiceRoutingManager ..> RingGroup : uses
    VoiceRoutingManager ..> ConferenceRoom : uses
    VoiceRoutingManager ..> IvrMenu : uses

    CallRoutingService ..> DidNumber : uses
    CallRoutingService ..> Extension : uses
    CallRoutingService ..> RingGroup : uses
    CallRoutingService ..> BusinessHours : uses
```