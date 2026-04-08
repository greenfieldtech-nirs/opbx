---
sidebar_position: 3
title: User Management
description: Managing users and roles in your organization
---

# User Management

OPBX uses a role-based access control system to manage what users can do within your organization. This page explains how to create, edit, and manage users.

## Accessing User Management

Navigate to the Users section using the sidebar menu. You will see a list of all users in your organization. The actions available to you depend on your role.

## Role Hierarchy

OPBX has four predefined roles with a clear hierarchy:

**Owner > PBX Admin > PBX User > Reporter**

Each role has specific capabilities. Higher roles can perform all actions of lower roles, with some exceptions noted below.

## Role Capabilities

| Capability | Owner | PBX Admin | PBX User | Reporter |
|-----------|-------|-----------|----------|----------|
| View users | Yes | Yes | No | No |
| Create users | Yes | Yes | No | No |
| Edit all users | Yes | Yes (not owners) | No | No |
| Delete users | Yes (not self) | PBX User/Reporter only | No | No |
| Change roles | Yes (not self) | No | No | No |
| Reset passwords | Yes | PBX User/Reporter only | No | No |
| Edit own profile | Yes | Yes | Yes | No |
| Manage settings | Yes | No | No | No |

:::note
Owners have full control except they cannot delete themselves or change their own role. This prevents accidental lockout from the organization.
:::

## Creating a User

To add a new user to your organization:

1. Click **Create User** on the Users page
2. Enter the user's full name
3. Enter a valid email address (used for login)
4. Set a temporary password
5. Select the appropriate role
6. Save the user

The new user receives login credentials and can access OPBX immediately. They should change their password on first login.

:::tip
Use descriptive names that help identify users across the system, especially if they will be assigned to extensions.
:::

## Editing Users

Click any user row to open the edit form. You can modify:

- Name
- Email address
- Role
- Status

:::warning
You cannot change your own role. This restriction prevents accidental self-demotion. Only another owner can change your role.
:::

## Deactivating Users

Set a user's status to **Inactive** to prevent login while preserving all data and history. This is useful for:

- Temporary leave
- Seasonal workers
- Investigation periods

Inactive users:
- Cannot log in
- Do not count toward license limits
- Retain all call history and recordings
- Can be reactivated at any time

## Deleting Users

Deleting a user removes them permanently from the system. This action:

- Cannot be undone
- Removes the user from all extensions and assignments
- Preserves call history (attributed to "Deleted User")

:::danger
You cannot delete yourself. You cannot delete the last owner in the organization. At least one owner must remain at all times.
:::

## Password Management

### Resetting Passwords

- **Owners** can reset any user's password
- **PBX Admins** can reset passwords for PBX Users and Reporters only
- Password resets generate a new temporary password

:::warning
When a password is changed, all active sessions and API tokens for that user are revoked. The user must log in again with the new password.
:::

### Password Requirements

Passwords must meet your organization's security policy. By default, OPBX requires:

- Minimum 8 characters
- At least one uppercase letter
- At least one lowercase letter
- At least one number

## Profile Management

Each user can edit their own profile through the top-right user menu:

1. Click your name in the top-right corner
2. Select **Profile**
3. Update your information:
   - Full name
   - Phone number
   - Physical address

:::note
Reporters cannot edit their own profile. An owner or PBX Admin must make profile changes for Reporter users.
:::

Profile information appears throughout the system, including call logs and reports.
