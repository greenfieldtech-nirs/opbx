# OPBX Frontend - Quick Start Guide

## 1. Install Dependencies

```bash
cd frontend
./setup.sh
```

**Or manually:**
```bash
npm install
cp .env.example .env
```

## 2. Configure Environment

Edit `frontend/.env`:

```env
VITE_API_BASE_URL=http://localhost:8000/api
VITE_WS_URL=ws://localhost:6001
VITE_APP_NAME=OPBX Admin
```

## 3. Start Development Server

```bash
npm run dev
```

Access at: **http://localhost:3000**

## 4. Login

Default credentials (if seeded):
- Email: `admin@example.com`
- Password: `password`

## Key Commands

```bash
# Development
npm run dev              # Start dev server (http://localhost:3000)
npm run build            # Production build
npm run preview          # Preview production build

# Quality
npm run type-check       # TypeScript type checking
npm run lint             # ESLint

# Docker
docker build -t opbx-frontend .
docker run -d -p 3000:80 opbx-frontend
```

## Project Structure

```
frontend/
├── src/
│   ├── components/      # React components
│   │   ├── ui/          # Base UI components (shadcn/ui)
│   │   ├── Layout/      # Layout components
│   │   ├── Users/       # User & extension forms
│   │   ├── DIDs/        # DID forms
│   │   ├── RingGroups/  # Ring group forms
│   │   ├── BusinessHours/ # Business hours forms
│   │   └── LiveCalls/   # Live call components
│   ├── pages/           # Page components (routes)
│   ├── services/        # API services
│   ├── hooks/           # Custom hooks
│   ├── types/           # TypeScript types
│   └── utils/           # Utilities
├── setup.sh             # Installation script
├── README.md            # Full documentation
├── IMPLEMENTATION.md    # Technical guide
└── FRONTEND_SUMMARY.md  # Implementation summary
```

## Key Features

### Pages
- `/login` - Authentication
- `/dashboard` - System overview
- `/users` - User management (Owner/Admin)
- `/extensions` - Extension management
- `/dids` - Phone number management (Owner/Admin)
- `/ring-groups` - Ring group configuration
- `/business-hours` - Business hours schedules
- `/call-logs` - Call history with filters
- `/live-calls` - Real-time active calls

### Components
- **Forms**: UserForm, ExtensionForm, DIDForm, RingGroupForm, BusinessHoursForm
- **Live Calls**: LiveCallList, LiveCallCard
- **Layout**: AppLayout, Header, Sidebar
- **UI**: Button, Card, Dialog, Input, Select, Switch, Badge, etc.

### API Services
- `auth.service.ts` - Authentication
- `users.service.ts` - Users CRUD
- `extensions.service.ts` - Extensions CRUD
- `dids.service.ts` - DIDs CRUD
- `ringGroups.service.ts` - Ring groups CRUD
- `businessHours.service.ts` - Business hours CRUD
- `callLogs.service.ts` - Call logs
- `websocket.service.ts` - Real-time updates

## Common Tasks

### Add a New Page

1. Create page component in `src/pages/`:
```typescript
export default function MyPage() {
  return <div>My Page</div>;
}
```

2. Add route in `src/router.tsx`:
```typescript
{
  path: '/my-page',
  element: <ProtectedRoute><MyPage /></ProtectedRoute>,
}
```

3. Add navigation item in `src/components/Layout/Sidebar.tsx`:
```typescript
{ name: 'My Page', href: '/my-page', icon: Icon }
```

### Add a New API Service

1. Create service in `src/services/`:
```typescript
import api from './api';

export const myService = {
  getAll: () => api.get('/my-resource').then(res => res.data),
  create: (data) => api.post('/my-resource', data).then(res => res.data),
};
```

2. Use with React Query:
```typescript
const { data } = useQuery({
  queryKey: ['my-resource'],
  queryFn: myService.getAll,
});
```

### Add a New Form

1. Create form component:
```typescript
export function MyForm({ onSubmit, onCancel }) {
  const { register, handleSubmit } = useForm();
  return <form onSubmit={handleSubmit(onSubmit)}>...</form>;
}
```

2. Use in dialog:
```typescript
<Dialog open={isOpen}>
  <DialogContent>
    <MyForm onSubmit={handleSubmit} onCancel={() => setIsOpen(false)} />
  </DialogContent>
</Dialog>
```

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `VITE_API_BASE_URL` | Backend API base URL | `http://localhost:8000/api` |
| `VITE_WS_URL` | WebSocket server URL | `ws://localhost:6001` |
| `VITE_APP_NAME` | Application name | `OPBX Admin` |

## Troubleshooting

### API connection fails
```bash
# Check .env file
cat .env

# Verify backend is running
curl http://localhost:8000/api/health
```

### WebSocket not connecting
```bash
# Check WebSocket URL in .env
# Ensure WebSocket server is running
# Check browser console for errors
```

### Build errors
```bash
# Clear and reinstall
rm -rf node_modules .vite package-lock.json
npm install

# Check TypeScript errors
npm run type-check
```

### Development server slow
```bash
# Increase Node.js memory
NODE_OPTIONS=--max_old_space_size=4096 npm run dev
```

## Documentation

- **Full Guide**: `README.md`
- **Technical Docs**: `IMPLEMENTATION.md`
- **Summary**: `FRONTEND_SUMMARY.md`
- **This Guide**: `QUICK_START.md`

## Support

- Backend API: Check Laravel documentation
- Cloudonix API: https://developers.cloudonix.com/
- shadcn/ui: https://ui.shadcn.com/
- React Query: https://tanstack.com/query/latest

## Production Deployment

### Build

```bash
npm run build
```

Output in `dist/` directory.

### Docker

```bash
docker build -t opbx-frontend .
docker run -d -p 3000:80 opbx-frontend
```

### Nginx Serving

Build output can be served by any static file server:

```nginx
server {
  listen 80;
  root /usr/share/nginx/html;
  index index.html;

  location / {
    try_files $uri $uri/ /index.html;
  }
}
```

---

**Ready to go!** Run `./setup.sh` and start building.
