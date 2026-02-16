/**
 * Phone Number Dialog Component
 *
 * Dialog for creating and editing phone numbers with routing configuration
 */

import { useEffect, useState } from 'react';
import type { DIDNumber, RoutingType, CreateDIDRequest, UpdateDIDRequest } from '@/types/api.types';
import { DestinationTypeAndSelector } from '@/components/destinations';
import type { DestinationType } from '@/components/destinations/types/destination.types';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Loader2, AlertCircle } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

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
      case 'ivr_menu':
        routing_config.ivr_menu_id = formData.target_id;
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

            {/* Unified Destination Selector */}
            <DestinationTypeAndSelector
              typeValue={formData.routing_type as DestinationType}
              destinationValue={formData.target_id}
              onChange={(type, destId) => {
                setFormData((prev) => ({
                  ...prev,
                  routing_type: type as RoutingType,
                  target_id: destId,
                }));
                // Clear target error if present
                if (formErrors.target_id) {
                  setFormErrors((prev) => {
                    const newErrors = { ...prev };
                    delete newErrors.target_id;
                    return newErrors;
                  });
                }
              }}
              layout="vertical"
              typeLabel="Route calls to"
              destinationLabel="Destination"
              allowedTypes={['extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'business_hours']}
            />
            {formErrors.target_id && (
              <p className="text-xs text-red-500">{formErrors.target_id}</p>
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
