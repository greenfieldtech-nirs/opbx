/**
 * Phone Number Dialog Component
 *
 * Dialog for creating and editing phone numbers with routing configuration
 */

import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { extensionsService } from '@/services/extensions.service';
import { ringGroupsService } from '@/services/ringGroups.service';
import { businessHoursService } from '@/services/businessHours.service';
import { conferenceRoomsService } from '@/services/conferenceRooms.service';
import type { DIDNumber, RoutingType, CreateDIDRequest, UpdateDIDRequest } from '@/types/api.types';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2, AlertCircle, ShieldCheck } from 'lucide-react';

interface PhoneNumberDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  phoneNumber?: DIDNumber | null;
  onSubmit: (data: CreateDIDRequest | UpdateDIDRequest) => void;
  isSubmitting?: boolean;
  error?: string | null;
}

export function PhoneNumberDialog({
  open,
  onOpenChange,
  phoneNumber,
  onSubmit,
  isSubmitting = false,
  error = null,
}: PhoneNumberDialogProps) {
  const isEditMode = !!phoneNumber;

  // Form state
  const [formData, setFormData] = useState({
    phone_number: '',
    friendly_name: '',
    status: 'active' as 'active' | 'inactive',
    routing_type: 'extension' as RoutingType,
    target_id: '',
  });

  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Reset form when dialog opens/closes or phone number changes
  useEffect(() => {
    if (open) {
      if (phoneNumber) {
        // Edit mode - populate with existing data
        const targetId =
          phoneNumber.routing_config.extension_id ||
          phoneNumber.routing_config.ring_group_id ||
          phoneNumber.routing_config.business_hours_schedule_id ||
          phoneNumber.routing_config.conference_room_id ||
          '';

        setFormData({
          phone_number: phoneNumber.phone_number,
          friendly_name: phoneNumber.friendly_name || '',
          status: phoneNumber.status,
          routing_type: phoneNumber.routing_type,
          target_id: targetId,
        });
      } else {
        // Create mode - reset to defaults
        setFormData({
          phone_number: '',
          friendly_name: '',
          status: 'active',
          routing_type: 'extension',
          target_id: '',
        });
      }
      setFormErrors({});
    }
  }, [open, phoneNumber]);

  // Fetch available extensions (active only, with user relationship)
  const { data: extensionsData } = useQuery({
    queryKey: ['extensions', { status: 'active', per_page: 100, with: 'user' }],
    queryFn: () => extensionsService.getAll({ status: 'active', per_page: 100, with: 'user' }),
    enabled: open && formData.routing_type === 'extension',
  });

  // Fetch available ring groups (active only)
  const { data: ringGroupsData } = useQuery({
    queryKey: ['ring-groups', { status: 'active', per_page: 100 }],
    queryFn: () => ringGroupsService.getAll({ status: 'active', per_page: 100 }),
    enabled: open && formData.routing_type === 'ring_group',
  });

  // Fetch available business hours schedules
  const { data: businessHoursData } = useQuery({
    queryKey: ['business-hours', { per_page: 100 }],
    queryFn: () => businessHoursService.getAll({ per_page: 100 }),
    enabled: open && formData.routing_type === 'business_hours',
  });

  // Fetch available conference rooms (active only)
  const { data: conferenceRoomsData } = useQuery({
    queryKey: ['conference-rooms', { status: 'active', per_page: 100 }],
    queryFn: () => conferenceRoomsService.getAll({ status: 'active', per_page: 100 }),
    enabled: open && formData.routing_type === 'conference_room',
  });

  const availableExtensions = extensionsData?.data || [];
  const availableRingGroups = ringGroupsData?.data || [];
  const availableBusinessHours = businessHoursData?.data || [];
  const availableConferenceRooms = conferenceRoomsData?.data || [];

  // Handle form field changes
  const handleFieldChange = (field: string, value: string) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    // Clear error for this field
    if (formErrors[field]) {
      setFormErrors((prev) => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  // Handle routing type change - reset target
  const handleRoutingTypeChange = (routingType: RoutingType) => {
    setFormData((prev) => ({
      ...prev,
      routing_type: routingType,
      target_id: '', // Reset target when routing type changes
    }));
  };

  // Validate form
  const validateForm = (): boolean => {
    const errors: Record<string, string> = {};

    // Phone number validation (only in create mode)
    if (!isEditMode && !formData.phone_number) {
      errors.phone_number = 'Phone number is required';
    } else if (!isEditMode && !/^\+[1-9]\d{1,14}$/.test(formData.phone_number)) {
      errors.phone_number = 'Phone number must be in E.164 format (+12125551234)';
    }

    // Target validation
    if (!formData.target_id) {
      errors.target_id = 'Please select a destination';
    }

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  // Handle form submission
  const handleSubmit = () => {
    if (!validateForm()) return;

    // Build routing_config based on routing_type
    const routing_config: any = {};
    switch (formData.routing_type) {
      case 'extension':
        routing_config.extension_id = formData.target_id;
        break;
      case 'ring_group':
        routing_config.ring_group_id = formData.target_id;
        break;
      case 'business_hours':
        routing_config.business_hours_schedule_id = formData.target_id;
        break;
      case 'conference_room':
        routing_config.conference_room_id = formData.target_id;
        break;
    }

    if (isEditMode) {
      // Update request (phone_number is immutable)
      const updateData: UpdateDIDRequest = {
        friendly_name: formData.friendly_name || undefined,
        routing_type: formData.routing_type,
        routing_config,
        status: formData.status,
      };
      onSubmit(updateData);
    } else {
      // Create request
      const createData: CreateDIDRequest = {
        phone_number: formData.phone_number,
        friendly_name: formData.friendly_name || undefined,
        routing_type: formData.routing_type,
        routing_config,
        status: formData.status,
      };
      onSubmit(createData);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {isEditMode ? `Edit Phone Number` : 'Add Phone Number'}
          </DialogTitle>
          <DialogDescription>
            {isEditMode
              ? 'Update routing configuration and settings for this phone number.'
              : 'Add a new phone number and configure where calls should be routed.'}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-6 py-4">
          {/* Error Alert */}
          {error && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {/* Section 1: Basic Information */}
          <div className="space-y-4">
            <h3 className="text-sm font-semibold">Basic Information</h3>

            {/* Phone Number - only shown on create, disabled on edit */}
            {!isEditMode && (
              <div className="space-y-2">
                <Label htmlFor="phone_number">
                  Phone Number <span className="text-red-500">*</span>
                </Label>
                <Input
                  id="phone_number"
                  value={formData.phone_number}
                  onChange={(e) => handleFieldChange('phone_number', e.target.value)}
                  placeholder="+12125551234"
                  className={formErrors.phone_number ? 'border-red-500' : ''}
                />
                <p className="text-xs text-muted-foreground">
                  Enter in E.164 format: +[country][number]
                </p>
                {formErrors.phone_number && (
                  <p className="text-xs text-red-500">{formErrors.phone_number}</p>
                )}
              </div>
            )}

            {/* Show phone number in edit mode (read-only) */}
            {isEditMode && (
              <div className="space-y-2">
                <Label>Phone Number</Label>
                <Input value={formData.phone_number} disabled className="bg-muted" />
                <p className="text-xs text-muted-foreground">
                  Phone number cannot be changed after creation
                </p>
              </div>
            )}

            {/* Friendly Name */}
            <div className="space-y-2">
              <Label htmlFor="friendly_name">Friendly Name</Label>
              <Input
                id="friendly_name"
                value={formData.friendly_name}
                onChange={(e) => handleFieldChange('friendly_name', e.target.value)}
                placeholder="e.g., Main Office, Support Hotline"
                maxLength={255}
              />
              <p className="text-xs text-muted-foreground">
                Optional: Give this number a memorable name
              </p>
            </div>

            {/* Status */}
            <div className="space-y-2">
              <Label>
                Status <span className="text-red-500">*</span>
              </Label>
              <RadioGroup
                value={formData.status}
                onValueChange={(value: 'active' | 'inactive') =>
                  handleFieldChange('status', value)
                }
              >
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="active" id="status-active" />
                  <Label htmlFor="status-active" className="font-normal cursor-pointer">
                    Active
                  </Label>
                </div>
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="inactive" id="status-inactive" />
                  <Label htmlFor="status-inactive" className="font-normal cursor-pointer">
                    Inactive
                  </Label>
                </div>
              </RadioGroup>
              <p className="text-xs text-muted-foreground">
                Inactive numbers will reject incoming calls
              </p>
            </div>
          </div>

          {/* Section 2: Routing Configuration */}
          <div className="space-y-4 border-t pt-4">
            <h3 className="text-sm font-semibold">Routing Configuration</h3>

            {/* Routing Type */}
            <div className="space-y-2">
              <Label htmlFor="routing_type">
                Route calls to <span className="text-red-500">*</span>
              </Label>
              <Select value={formData.routing_type} onValueChange={handleRoutingTypeChange}>
                <SelectTrigger id="routing_type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="extension">Extension</SelectItem>
                  <SelectItem value="ring_group">Ring Group</SelectItem>
                  <SelectItem value="business_hours">Business Hours</SelectItem>
                  <SelectItem value="conference_room">Conference Room</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Choose where calls should be routed
              </p>
            </div>

            {/* Conditional Target Fields */}
            {formData.routing_type === 'extension' && (
              <div className="space-y-2">
                <Label htmlFor="target_extension">
                  Target Extension <span className="text-red-500">*</span>
                </Label>
                <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                  <SelectTrigger id="target_extension" className={formErrors.target_id ? 'border-red-500' : ''}>
                    <SelectValue placeholder="Select an extension" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableExtensions.map((ext) => (
                      <SelectItem key={ext.id} value={ext.id}>
                        {ext.extension_number} - {ext.user?.name || 'Unassigned'}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Calls will ring this extension directly
                </p>
                {formErrors.target_id && (
                  <p className="text-xs text-red-500">{formErrors.target_id}</p>
                )}
              </div>
            )}

            {formData.routing_type === 'ring_group' && (
              <div className="space-y-2">
                <Label htmlFor="target_ring_group">
                  Target Ring Group <span className="text-red-500">*</span>
                </Label>
                <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                  <SelectTrigger id="target_ring_group" className={formErrors.target_id ? 'border-red-500' : ''}>
                    <SelectValue placeholder="Select a ring group" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableRingGroups.map((rg) => (
                      <SelectItem key={rg.id} value={rg.id}>
                        {rg.name} ({rg.members?.length || 0} members)
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Calls will use this ring group's strategy
                </p>
                {formErrors.target_id && (
                  <p className="text-xs text-red-500">{formErrors.target_id}</p>
                )}
              </div>
            )}

            {formData.routing_type === 'business_hours' && (
              <div className="space-y-2">
                <Label htmlFor="target_business_hours">
                  Business Hours Schedule <span className="text-red-500">*</span>
                </Label>
                <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                  <SelectTrigger id="target_business_hours" className={formErrors.target_id ? 'border-red-500' : ''}>
                    <SelectValue placeholder="Select a schedule" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableBusinessHours.map((bh) => (
                      <SelectItem key={bh.id} value={bh.id}>
                        {bh.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Calls will route based on time of day
                </p>
                {formErrors.target_id && (
                  <p className="text-xs text-red-500">{formErrors.target_id}</p>
                )}
              </div>
            )}

            {formData.routing_type === 'conference_room' && (
              <div className="space-y-2">
                <Label htmlFor="target_conference_room">
                  Target Conference Room <span className="text-red-500">*</span>
                </Label>
                <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                  <SelectTrigger id="target_conference_room" className={formErrors.target_id ? 'border-red-500' : ''}>
                    <SelectValue placeholder="Select a conference room" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableConferenceRooms.map((cr) => (
                      <SelectItem key={cr.id} value={cr.id}>
                        {cr.name} ({cr.max_participants} max)
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Calls will join this conference room
                </p>
                {formErrors.target_id && (
                  <p className="text-xs text-red-500">{formErrors.target_id}</p>
                )}
              </div>
            )}
            {/* Sentry Protection Info */}
            <Alert className="bg-blue-50 border-blue-200">
              <ShieldCheck className="h-4 w-4 text-blue-600" />
              <AlertDescription className="text-blue-700 text-xs">
                This number is automatically protected by <strong>Routing Sentry</strong>.
                Inbound call velocity and volume are monitored based on global settings.
              </AlertDescription>
            </Alert>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={isSubmitting}>
            {isSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {isEditMode ? 'Update' : 'Create'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
