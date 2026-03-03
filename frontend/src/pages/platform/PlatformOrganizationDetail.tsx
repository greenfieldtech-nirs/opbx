/**
 * Platform Organization Detail Page
 *
 * View and manage a specific organization.
 */

import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Building2, Users, Phone, Globe, Save, PauseCircle, Play, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PlatformLayout } from '@/components/platform';
import { OrganizationStatusBadge } from '@/components/platform';
import { usePlatformOrganization, useUpdateOrganizationSettings, useUpdateOrganizationStatus } from '@/hooks/platform';
import { toast } from 'sonner';
import { useState, useEffect } from 'react';

export default function PlatformOrganizationDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: organization, isLoading } = usePlatformOrganization(id || '');
  const updateSettings = useUpdateOrganizationSettings();
  const updateStatus = useUpdateOrganizationStatus();

  const [formData, setFormData] = useState({
    name: '',
    timezone: '',
  });

  useEffect(() => {
    if (organization) {
      setFormData({
        name: organization.name,
        timezone: organization.timezone,
      });
    }
  }, [organization]);

  const handleSave = async () => {
    if (!id) return;
    try {
      await updateSettings.mutateAsync({
        id,
        data: formData,
      });
      toast.success('Organization settings updated');
    } catch {
      toast.error('Failed to update settings');
    }
  };

  const handleStatusChange = async (newStatus: 'active' | 'suspended' | 'deleted') => {
    if (!id) return;
    try {
      await updateStatus.mutateAsync({
        id,
        data: { status: newStatus },
      });
      toast.success(`Organization ${newStatus}`);
    } catch {
      toast.error('Failed to update status');
    }
  };

  if (isLoading) {
    return (
      <PlatformLayout>
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
        </div>
      </PlatformLayout>
    );
  }

  if (!organization) {
    return (
      <PlatformLayout>
        <div className="text-center py-12">
          <Building2 className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
          <h2 className="text-lg font-semibold mb-2">Organization not found</h2>
          <Button onClick={() => navigate('/ui/platform/organizations')}>
            Back to Organizations
          </Button>
        </div>
      </PlatformLayout>
    );
  }

  return (
    <PlatformLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Button variant="ghost" size="icon" onClick={() => navigate('/ui/platform/organizations')}>
              <ArrowLeft className="h-5 w-5" />
            </Button>
            <div>
              <h1 className="text-2xl font-bold">{organization.name}</h1>
              <p className="text-muted-foreground">{organization.slug}</p>
            </div>
            <OrganizationStatusBadge status={organization.status} />
          </div>

          <div className="flex items-center gap-2">
            {organization.status === 'active' && (
              <>
                <Button
                  variant="outline"
                  onClick={() => handleStatusChange('suspended')}
                  disabled={updateStatus.isPending}
                >
                  <PauseCircle className="h-4 w-4 mr-2" />
                  Suspend
                </Button>
                <Button
                  variant="destructive"
                  onClick={() => handleStatusChange('deleted')}
                  disabled={updateStatus.isPending}
                >
                  <Trash2 className="h-4 w-4 mr-2" />
                  Delete
                </Button>
              </>
            )}
            {organization.status === 'suspended' && (
              <>
                <Button
                  variant="default"
                  onClick={() => handleStatusChange('active')}
                  disabled={updateStatus.isPending}
                >
                  <Play className="h-4 w-4 mr-2" />
                  Reactivate
                </Button>
                <Button
                  variant="destructive"
                  onClick={() => handleStatusChange('deleted')}
                  disabled={updateStatus.isPending}
                >
                  <Trash2 className="h-4 w-4 mr-2" />
                  Delete
                </Button>
              </>
            )}
          </div>
        </div>

        <Tabs defaultValue="overview" className="space-y-4">
          <TabsList>
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="settings">Settings</TabsTrigger>
            <TabsTrigger value="users">Users</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="space-y-4">
            <div className="grid gap-4 md:grid-cols-4">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium">Users</CardTitle>
                  <Users className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{organization.users_count}</div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium">Extensions</CardTitle>
                  <Phone className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{organization.extensions_count}</div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium">DIDs</CardTitle>
                  <Globe className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{organization.dids_count}</div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium">Ring Groups</CardTitle>
                  <Users className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{organization.ring_groups_count}</div>
                </CardContent>
              </Card>
            </div>
          </TabsContent>

          <TabsContent value="settings" className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle>Organization Settings</CardTitle>
                <CardDescription>Update organization name and settings</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="name">Organization Name</Label>
                  <Input
                    id="name"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="timezone">Timezone</Label>
                  <Input
                    id="timezone"
                    value={formData.timezone}
                    onChange={(e) => setFormData({ ...formData, timezone: e.target.value })}
                  />
                </div>
                <Button onClick={handleSave} disabled={updateSettings.isPending}>
                  <Save className="h-4 w-4 mr-2" />
                  Save Changes
                </Button>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="users">
            <Card>
              <CardHeader>
                <CardTitle>Users</CardTitle>
                <CardDescription>Users belonging to this organization</CardDescription>
              </CardHeader>
              <CardContent>
                {organization.users.length === 0 ? (
                  <p className="text-muted-foreground">No users in this organization.</p>
                ) : (
                  <div className="space-y-2">
                    {organization.users.map((user) => (
                      <div
                        key={user.id}
                        className="flex items-center justify-between p-3 rounded-lg border"
                      >
                        <div>
                          <p className="font-medium">{user.name}</p>
                          <p className="text-sm text-muted-foreground">{user.email}</p>
                        </div>
                        <div className="flex items-center gap-2">
                          <span className="text-sm text-muted-foreground capitalize">{user.role}</span>
                          <Button variant="ghost" size="sm" onClick={() => navigate(`/ui/platform/users?user=${user.id}`)}>
                            View
                          </Button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </PlatformLayout>
  );
}
