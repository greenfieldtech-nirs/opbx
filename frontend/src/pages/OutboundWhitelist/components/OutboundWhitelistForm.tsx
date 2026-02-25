/**
 * Outbound Whitelist Form Component
 *
 * Shared form component for creating and editing outbound whitelist entries
 */

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { RefreshCw } from 'lucide-react';
import { Combobox } from '@/components/ui/combobox';
import { cn } from '@/lib/utils';
import type { CloudonixTrunk } from '@/services/settings.service';

interface FormData {
  name: string;
  destination_country: string;
  destination_prefix?: string;
  outbound_trunk_name: string;
}

interface OutboundWhitelistFormProps {
  formData: FormData;
  formErrors: Partial<FormData>;
  countryOptions: Array<{ value: string; label: string }>;
  trunks: CloudonixTrunk[];
  trunksLoading: boolean;
  onChange: (data: FormData) => void;
  onRefreshTrunks: () => void;
}

export function OutboundWhitelistForm({
  formData,
  formErrors,
  countryOptions,
  trunks,
  trunksLoading,
  onChange,
  onRefreshTrunks,
}: OutboundWhitelistFormProps) {
  return (
    <div className="space-y-4 py-4">
      <div>
        <Label htmlFor="name">Name</Label>
        <Input
          id="name"
          value={formData.name}
          onChange={(e) => onChange({ ...formData, name: e.target.value })}
          placeholder="e.g., Local Calls, Emergency Numbers"
          required
        />
        {formErrors.name && (
          <p className="text-sm text-destructive mt-1">{formErrors.name}</p>
        )}
      </div>

      <div>
        <Label htmlFor="destination_country">Country</Label>
        <Combobox
          options={countryOptions}
          value={formData.destination_country}
          onValueChange={(value) => onChange({ ...formData, destination_country: value })}
          placeholder="Select destination country"
          searchPlaceholder="Search countries..."
          emptyText="No country found."
          buttonClassName="w-full"
          contentClassName="w-[--radix-popover-trigger-width]"
        />
        {formErrors.destination_country && (
          <p className="text-sm text-destructive mt-1">{formErrors.destination_country}</p>
        )}
      </div>

      <div>
        <Label htmlFor="destination_prefix">Additional Prefix</Label>
        <Input
          id="destination_prefix"
          value={formData.destination_prefix}
          onChange={(e) => onChange({ ...formData, destination_prefix: e.target.value })}
          placeholder="e.g., 972, 212 (area code without country code)"
        />
        <p className="text-xs text-muted-foreground mt-1">
          Area code or prefix (without country code). Will be combined with selected country.
        </p>
        {formErrors.destination_prefix && (
          <p className="text-sm text-destructive mt-1">{formErrors.destination_prefix}</p>
        )}
      </div>

      <div>
        <div className="flex items-center justify-between mb-2">
          <Label htmlFor="outbound_trunk_name">Voice Trunk</Label>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onRefreshTrunks}
            disabled={trunksLoading}
            className="h-6 px-2"
          >
            <RefreshCw className={cn('h-3 w-3', trunksLoading && 'animate-spin')} />
          </Button>
        </div>
        {trunks.length > 0 ? (
          <Select
            value={formData.outbound_trunk_name}
            onValueChange={(value) => onChange({ ...formData, outbound_trunk_name: value })}
            required
          >
            <SelectTrigger>
              <SelectValue placeholder="Select a voice trunk" />
            </SelectTrigger>
            <SelectContent>
              {trunks.map((trunk) => (
                <SelectItem key={trunk.id} value={trunk.name}>
                  {trunk.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : (
          <div className="text-center py-4 border border-destructive/20 rounded-md bg-destructive/5">
            <p className="text-sm text-destructive font-medium">No outbound trunks available</p>
            <p className="text-xs text-muted-foreground mt-1">
              Configure Cloudonix settings to fetch available trunks
            </p>
          </div>
        )}
        {formErrors.outbound_trunk_name && (
          <p className="text-sm text-destructive mt-1">{formErrors.outbound_trunk_name}</p>
        )}
      </div>
    </div>
  );
}

export default OutboundWhitelistForm;
