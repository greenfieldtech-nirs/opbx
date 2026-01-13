# OpBX Technical Documentation

## Overview

This documentation provides comprehensive technical details for the OpBX open-source business PBX application. OpBX is a containerized PBX system built on Cloudonix CPaaS that provides enterprise-grade call routing, real-time monitoring, and administrative management.

## Documentation Structure

### Core Architecture
- **[Architecture Overview](architecture-overview.md)** - High-level system design, control vs execution planes, technology stack
- **[Database Schema](database-schema.md)** - Complete database design with tables, relationships, and constraints
- **[Laravel Backend](laravel-structure.md)** - PHP/Laravel implementation details and patterns

### API & Integration
- **[API & Webhooks](api-webhooks.md)** - REST API endpoints, webhook contracts, authentication
- **[Call Flows & State Machine](call-flows-state-machine.md)** - Call routing logic, state transitions, event flows
- **[Real-Time Features](realtime-websockets.md)** - WebSocket implementation, broadcasting, presence

### Implementation Details
- **[React Frontend](react-structure.md)** - Frontend architecture, components, state management
- **[Docker Setup](docker-setup.md)** - Containerization, environment, deployment
- **[ngrok Setup](ngrok-setup.md)** - Local webhook development with tunneling
- **[Security Implementation](security-implementation.md)** - Authentication, authorization, multi-tenancy

## Quick Start

### Development Environment

1. **Prerequisites**
   - Docker Desktop
   - ngrok account
   - Git

2. **Setup**
   ```bash
   git clone https://github.com/your-org/opbx.git
   cd opbx
   cp .env.example .env
   # Add your ngrok authtoken to .env
   docker compose up -d
   ```

3. **Initial Configuration**
   ```bash
   docker compose exec app php artisan key:generate
   docker compose exec app php artisan migrate
   docker compose exec frontend npm install
   ```

4. **Access Points**
   - Frontend: http://localhost:3000
   - API: http://localhost/api/v1
   - ngrok Interface: http://localhost:4040

### Key Features

#### Multi-Tenant PBX
- Complete organization isolation
- Role-based access control (Owner/Admin/User/Reporter)
- Secure data separation

#### Call Management
- Inbound call routing (DID, ring groups, business hours, IVR)
- Real-time call monitoring
- Call detail records and analytics
- Voicemail and conference rooms

#### Real-Time Features
- Live call presence updates
- WebSocket broadcasting
- Instant UI notifications

#### Cloudonix Integration
- Webhook-based call processing
- CXML response generation
- API authentication and rate limiting

## Architecture Principles

### Control Plane vs Execution Plane

**Control Plane** (Configuration)
- React SPA for administrative interface
- Laravel API for CRUD operations
- MySQL for persistent configuration
- User management and PBX setup

**Execution Plane** (Runtime)
- Webhook ingestion for call events
- Redis-based caching and state management
- Real-time call routing decisions
- CXML generation for Cloudonix

### Security First

- **Multi-tenancy**: Zero-trust organization isolation
- **Authentication**: Laravel Sanctum with dual modes
- **Authorization**: Policy-based RBAC
- **Webhook Security**: HMAC-SHA256 signature verification
- **Input Validation**: Comprehensive request sanitization

### Performance & Scalability

- **Caching**: Multi-layer Redis caching
- **Queue Processing**: Background job handling
- **Real-Time**: WebSocket broadcasting with fallbacks
- **Containerization**: Docker-first deployment

## Development Guidelines

### Code Organization
- **Laravel**: Domain-driven design with service layers
- **React**: Feature-based component organization
- **Database**: Normalized schema with proper indexing
- **Security**: Defense-in-depth approach

### Testing Strategy
- **Unit Tests**: Business logic and utilities
- **Feature Tests**: API endpoints and integration
- **Security Tests**: Authentication and authorization
- **Performance Tests**: Load testing and optimization

### Deployment
- **Development**: Docker Compose with hot reload
- **Production**: Container orchestration with scaling
- **CI/CD**: Automated testing and deployment pipelines

## Contributing

### Getting Started
1. Review the architecture documentation
2. Set up the development environment
3. Choose an issue or feature to work on
4. Follow the established patterns and conventions

### Code Standards
- **PHP**: PSR-12 coding standards
- **TypeScript**: Strict mode with ESLint
- **Testing**: Comprehensive test coverage
- **Documentation**: Inline code documentation

### Pull Request Process
1. Create a feature branch
2. Write tests for new functionality
3. Update documentation as needed
4. Ensure all tests pass
5. Submit PR with detailed description

## Support & Resources

### Community
- **GitHub Issues**: Bug reports and feature requests
- **Discussions**: General questions and community support
- **Contributing Guide**: Detailed contribution guidelines

### Cloudonix Resources
- **Developer Portal**: https://developers.cloudonix.com/
- **API Documentation**: Cloudonix REST API reference
- **Webhook Guide**: Real-time event handling

### Additional Documentation
- **API Reference**: Complete endpoint documentation
- **Migration Guide**: Database schema changes
- **Troubleshooting**: Common issues and solutions

## License

This project is licensed under the MIT License - see the LICENSE file for details.

---

**OpBX** is an open-source project that brings enterprise PBX capabilities to businesses of all sizes. Built with modern technologies and security best practices, it provides a solid foundation for business communications infrastructure.