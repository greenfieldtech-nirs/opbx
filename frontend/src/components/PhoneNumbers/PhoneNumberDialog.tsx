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
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
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
    enable_non_e164: false,
  });

  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Reset form when dialog opens/closes or phone number changes
  useEffect(() => {
    if (open) {
      if (phoneNumber) {
        // Edit mode - populate with existing data
        const targetId =
          phoneNumber.routing_config.extension_id ||
          phoneNumber.routing_config.ai_assistant_id ||
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
          enable_non_e164: false,
        });
      } else {
        // Create mode - reset to defaults
        setFormData({
          phone_number: '',
          friendly_name: '',
          status: 'active',
          routing_type: 'extension',
          target_id: '',
          enable_non_e164: false,
        });
      }
      setFormErrors({});
    }
  }, [open, phoneNumber]);

  // Fetch available PBX user extensions (active only, with user relationship)
  const { data: pbxUserExtensionsData } = useQuery({
    queryKey: ['extensions', { type: 'user', status: 'active', per_page: 100, with: 'user' }],
    queryFn: () => extensionsService.getAll({ type: 'user', status: 'active', per_page: 100, with: 'user' }),
    enabled: open && formData.routing_type === 'extension',
  });

  // Fetch available AI assistant extensions (active only)
  const { data: aiAssistantExtensionsData } = useQuery({
    queryKey: ['extensions', { type: 'ai_assistant', status: 'active', per_page: 100 }],
    queryFn: () => extensionsService.getAll({ type: 'ai_assistant', status: 'active', per_page: 100 }),
    enabled: open && formData.routing_type === 'ai_assistant',
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

  const availablePbxUserExtensions = pbxUserExtensionsData?.data || [];
  const availableAiAssistantExtensions = aiAssistantExtensionsData?.data || [];
  const availableRingGroups = ringGroupsData?.data || [];
  const availableBusinessHours = businessHoursData?.data || [];
  const availableConferenceRooms = conferenceRoomsData?.data || [];

  // Handle form field changes
  const handleFieldChange = (field: string, value: any) => {
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
    } else if (!isEditMode && !formData.enable_non_e164 && !/^\+[1-9]\d{1,14}$/.test(formData.phone_number)) {
      errors.phone_number = 'Phone number must be in E.164 format (+12125551234)';
    } else if (!isEditMode && formData.enable_non_e164 && !/^[\d+#]+$/.test(formData.phone_number.replace(/^\+/, ''))) {
      errors.phone_number = 'Phone number can only contain digits, +, and # characters';
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
      case 'ai_assistant':
        routing_config.ai_assistant_id = formData.target_id;
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

            {/* Phone Number - only shown on create, disabled on edit */}
            {!isEditMode && (
              <div className="space-y-2">
                <Label htmlFor="phone_number">
                  Phone Number <span className="text-red-500">*</span>
                </Label>
                <div className="flex items-center gap-4">
                  <div className="flex-1">
                    <Input
                      id="phone_number"
                      value={formData.phone_number}
                      onChange={(e) => handleFieldChange('phone_number', e.target.value)}
                      placeholder="+12125551234"
                      className={formErrors.phone_number ? 'border-red-500' : ''}
                    />
                  </div>
                  <div className="flex items-center gap-2">
                    <Switch
                      checked={formData.status === 'active'}
                      onCheckedChange={(checked) =>
                        handleFieldChange('status', checked ? 'active' : 'inactive')
                      }
                    />
                    <span className="text-sm font-medium">
                      {formData.status === 'active' ? 'Active' : 'Disabled'}
                    </span>
                  </div>
                </div>

                {/* Enable non-E.164 checkbox */}
                <div className="flex items-center space-x-2 mt-2">
                  <Checkbox
                    id="enable_non_e164"
                    checked={formData.enable_non_e164}
                    onCheckedChange={(checked) =>
                      handleFieldChange('enable_non_e164', checked === true)
                    }
                  />
                  <Label htmlFor="enable_non_e164" className="text-sm font-normal cursor-pointer">
                    Enable non-E.164 Phone Numbers
                  </Label>
                </div>

                <p className="text-xs text-muted-foreground">
                  {formData.enable_non_e164
                    ? 'Enter any phone number using digits, +, and # characters'
                    : 'Enter in E.164 format: +[country][number]'
                  }
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
                <div className="flex items-center gap-4">
                  <div className="flex-1">
                    <Input value={formData.phone_number} disabled className="bg-muted" />
                  </div>
                  <div className="flex items-center gap-2">
                    <Switch
                      checked={formData.status === 'active'}
                      onCheckedChange={(checked) =>
                        handleFieldChange('status', checked ? 'active' : 'inactive')
                      }
                    />
                    <span className="text-sm font-medium">
                      {formData.status === 'active' ? 'Active' : 'Disabled'}
                    </span>
                  </div>
                </div>
                <p className="text-xs text-muted-foreground">
                  Phone number cannot be changed after creation
                </p>
              </div>
            )}




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
                   <SelectItem value="extension">PBX User Extension</SelectItem>
                   <SelectItem value="ai_assistant">AI Assistant Extension</SelectItem>
                   <SelectItem value="ring_group">Ring Group</SelectItem>
                   <SelectItem value="conference_room">Conference Room</SelectItem>
                   <SelectItem value="ivr_menu">IVR Menu</SelectItem>
                   <SelectItem value="business_hours">Business Hours</SelectItem>
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
                   PBX User Extension <span className="text-red-500">*</span>
                 </Label>
                 <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                   <SelectTrigger id="target_extension" className={formErrors.target_id ? 'border-red-500' : ''}>
                     <SelectValue placeholder="Select a PBX user extension" />
                   </SelectTrigger>
                   <SelectContent>
                     {availablePbxUserExtensions.map((extension) => (
                       <SelectItem key={extension.id} value={extension.id}>
                         <div className="flex items-center gap-2">
                           <div className={`w-2 h-2 rounded-full ${extension.status === 'active' ? 'bg-green-500' : 'bg-yellow-500'}`}></div>
                           <span className="font-mono">{extension.extension_number}</span>
                           <span className="text-muted-foreground">
                             {extension.user ? extension.user.name : 'Unassigned'}
                           </span>
                         </div>
                       </SelectItem>
                     ))}
                   </SelectContent>
                 </Select>
                 <p className="text-xs text-muted-foreground">
                   Calls will ring the selected PBX user directly
                 </p>
                 {formErrors.target_id && (
                   <p className="text-xs text-red-500">{formErrors.target_id}</p>
                 )}
               </div>
             )}

             {formData.routing_type === 'ai_assistant' && (
               <div className="space-y-2">
                 <Label htmlFor="target_ai_assistant">
                   AI Assistant Extension <span className="text-red-500">*</span>
                 </Label>
                 <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                   <SelectTrigger id="target_ai_assistant" className={formErrors.target_id ? 'border-red-500' : ''}>
                     <SelectValue placeholder="Select an AI assistant extension" />
                   </SelectTrigger>
                   <SelectContent>
                     {availableAiAssistantExtensions.map((extension) => (
                       <SelectItem key={extension.id} value={extension.id}>
                         <div className="flex items-center gap-2">
                           <div className={`w-2 h-2 rounded-full ${extension.status === 'active' ? 'bg-blue-500' : 'bg-yellow-500'}`}></div>
                           <span className="font-mono">{extension.extension_number}</span>
                           <span className="text-muted-foreground">
                             {extension.configuration?.provider ? `${extension.configuration.provider} Assistant` : 'AI Assistant'}
                           </span>
                           <span className="text-xs bg-blue-100 text-blue-800 px-1 rounded">AI</span>
                         </div>
                       </SelectItem>
                     ))}
                   </SelectContent>
                 </Select>
                 <p className="text-xs text-muted-foreground">
                   Calls will be handled by the selected AI assistant
                 </p>
                 {formErrors.target_id && (
                   <p className="text-xs text-red-500">{formErrors.target_id}</p>
                 )}
               </div>
             )}

            {formData.routing_type === 'ring_group' && (
              <div className="space-y-2">
                <Label htmlFor="target_ring_group">
                  Ring Group <span className="text-red-500">*</span>
                </Label>
                <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                  <SelectTrigger id="target_ring_group" className={formErrors.target_id ? 'border-red-500' : ''}>
                    <SelectValue placeholder="Select a ring group" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableRingGroups.map((group) => (
                      <SelectItem key={group.id} value={group.id}>
                        <div className="flex items-center gap-2">
                          <div className={`w-2 h-2 rounded-full ${group.status === 'active' ? 'bg-green-500' : 'bg-gray-400'}`}></div>
                          <span>{group.name}</span>
                          <span className="text-xs bg-purple-100 text-purple-800 px-1 rounded">
                            {group.members.length} member{group.members.length !== 1 ? 's' : ''}
                          </span>
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Calls will ring all members according to the group's strategy
                </p>
                {formErrors.target_id && (
                  <p className="text-xs text-red-500">{formErrors.target_id}</p>
                )}
              </div>
            )}

            {formData.routing_type === 'conference_room' && (
              <div className="space-y-2">
                <Label htmlFor="target_conference_room">
                  Conference Room <span className="text-red-500">*</span>
                </Label>
                <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                  <SelectTrigger id="target_conference_room" className={formErrors.target_id ? 'border-red-500' : ''}>
                    <SelectValue placeholder="Select a conference room" />
                  </SelectTrigger>
                  <SelectContent>
                    {/* Mock conference rooms */}
                    <SelectItem value="conf-1">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-orange-500"></div>
                        <span>Sales Meeting Room</span>
                        <span className="text-xs bg-orange-100 text-orange-800 px-1 rounded">25 max</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="conf-2">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-orange-500"></div>
                        <span>Executive Boardroom</span>
                        <span className="text-xs bg-orange-100 text-orange-800 px-1 rounded">10 max</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="conf-3">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-green-500"></div>
                        <span>Training Room</span>
                        <span className="text-xs bg-orange-100 text-orange-800 px-1 rounded">50 max</span>
                      </div>
                    </SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Calls will join the selected conference room
                </p>
                {formErrors.target_id && (
                  <p className="text-xs text-red-500">{formErrors.target_id}</p>
                )}
              </div>
            )}

            {formData.routing_type === 'ivr_menu' && (
              <div className="space-y-2">
                <Label htmlFor="target_ivr_menu">
                  IVR Menu <span className="text-red-500">*</span>
                </Label>
                <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                  <SelectTrigger id="target_ivr_menu" className={formErrors.target_id ? 'border-red-500' : ''}>
                    <SelectValue placeholder="Select an IVR menu" />
                  </SelectTrigger>
                  <SelectContent>
                    {/* Mock IVR menus */}
                    <SelectItem value="ivr-1">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-indigo-500"></div>
                        <span>Main Menu</span>
                        <span className="text-xs bg-indigo-100 text-indigo-800 px-1 rounded">IVR</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="ivr-2">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-indigo-500"></div>
                        <span>Support Menu</span>
                        <span className="text-xs bg-indigo-100 text-indigo-800 px-1 rounded">IVR</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="ivr-3">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-green-500"></div>
                        <span>Emergency Menu</span>
                        <span className="text-xs bg-indigo-100 text-indigo-800 px-1 rounded">IVR</span>
                      </div>
                    </SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Calls will be handled by the selected IVR menu
                </p>
                {formErrors.target_id && (
                  <p className="text-xs text-red-500">{formErrors.target_id}</p>
                )}
              </div>
            )}

            {formData.routing_type === 'business_hours' && (
              <div className="space-y-2">
                <Label htmlFor="target_business_hours">
                  Business Hours <span className="text-red-500">*</span>
                </Label>
                <Select value={formData.target_id} onValueChange={(val) => handleFieldChange('target_id', val)}>
                  <SelectTrigger id="target_business_hours" className={formErrors.target_id ? 'border-red-500' : ''}>
                    <SelectValue placeholder="Select business hours" />
                  </SelectTrigger>
                  <SelectContent>
                    {/* Mock business hours schedules */}
                    <SelectItem value="bh-1">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-teal-500"></div>
                        <span>Office Hours</span>
                        <span className="text-xs bg-teal-100 text-teal-800 px-1 rounded">Mon-Fri 9AM-5PM</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="bh-2">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-teal-500"></div>
                        <span>Extended Hours</span>
                        <span className="text-xs bg-teal-100 text-teal-800 px-1 rounded">Mon-Sat 8AM-8PM</span>
                      </div>
                    </SelectItem>
                    <SelectItem value="bh-3">
                      <div className="flex items-center gap-2">
                        <div className="w-2 h-2 rounded-full bg-green-500"></div>
                        <span>24/7 Support</span>
                        <span className="text-xs bg-teal-100 text-teal-800 px-1 rounded">Always Open</span>
                      </div>
                    </SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Calls will route based on the selected business hours schedule
                </p>
                {formErrors.target_id && (
                  <p className="text-xs text-red-500">{formErrors.target_id}</p>
                )}
              </div>
            )}

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
