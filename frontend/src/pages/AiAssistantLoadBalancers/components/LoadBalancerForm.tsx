/**
 * Load Balancer Form Component
 *
 * Form for creating/editing AI Assistant Load Balancers
 */

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
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Switch } from '@/components/ui/switch';
import { AlbsStrategySelector } from '@/components/design-system';
import {
  Plus,
  X,
  Info,
  GripVertical,
  Bot,
  PhoneForwarded,
  Users,
  Menu,
  PhoneOff,
  Phone,
  Bot as BotIcon,
} from 'lucide-react';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { AlbsStrategy, Status, RingGroupFallbackAction } from '@/types';

// Sortable item component for drag-and-drop
interface SortableItemProps {
  id: string;
  children: (dragHandleProps: any) => React.ReactNode;
}

function SortableItem({ id, children }: SortableItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  const dragHandleProps = {
    ...attributes,
    ...listeners,
  };

  return (
    <div ref={setNodeRef} style={style}>
      {children(dragHandleProps)}
    </div>
  );
}

interface MemberFormData {
  ai_assistant_id: string;
  ai_assistant_name: string;
  weight: number;
  position: number;
}

interface FormData {
  name: string;
  description: string;
  strategy: AlbsStrategy;
  follow_through: boolean;
  status: Status;
  fallback_action: RingGroupFallbackAction;
  fallback_extension_id?: string;
  fallback_ring_group_id?: string;
  fallback_ivr_menu_id?: string;
  fallback_ai_assistant_id?: string;
  members: MemberFormData[];
}

interface LoadBalancerFormProps {
  formData: FormData;
  formErrors: Record<string, string>;
  availableAiAssistants: Array<{ id: string | number; name: string }>;
  availableExtensions: Array<{ id: string | number; extension_number: string; user?: { name: string } }>;
  availableRingGroups: Array<{ id: string | number; name: string }>;
  availableIvrMenus: Array<{ id: string | number; name: string }>;
  onChange: (data: FormData) => void;
  onAddMember: () => void;
  onRemoveMember: (assistantId: string) => void;
  onDragEnd: (event: any) => void;
}

const WEIGHT_OPTIONS = Array.from({ length: 21 }, (_, i) => i * 5);

function getStrategyDescription(strategy: AlbsStrategy): string {
  const descriptions: Record<AlbsStrategy, string> = {
    round_robin: 'Distribute calls evenly across AI assistants in sequential order.',
    priority: 'Route calls to AI assistants in priority order using drag and drop.',
    percentage: 'Route calls based on configured weight percentages.',
  };
  return descriptions[strategy] || '';
}

export function LoadBalancerForm({
  formData,
  formErrors,
  availableAiAssistants,
  availableExtensions,
  availableRingGroups,
  availableIvrMenus,
  onChange,
  onAddMember,
  onRemoveMember,
  onDragEnd,
}: LoadBalancerFormProps) {
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  const totalWeight = formData.members.reduce((sum, m) => sum + (m.weight || 0), 0);
  const availableAiAssistantsForMembers = availableAiAssistants.filter(
    a => !formData.members.some(m => m.ai_assistant_id === String(a.id))
  );

  return (
    <div className="space-y-4 py-4">
      <Alert>
        <Info className="h-4 w-4" />
        <AlertDescription>
          Only active AI Assistants can be added to load balancers.
        </AlertDescription>
      </Alert>

      {/* Name */}
      <div className="space-y-2">
        <Label htmlFor="name">
          Name <span className="text-red-500">*</span>
        </Label>
        <Input
          id="name"
          value={formData.name || ''}
          onChange={(e) => onChange({ ...formData, name: e.target.value })}
          placeholder="e.g., Support AI Pool"
          className={formErrors.name ? 'border-red-500' : ''}
        />
        {formErrors.name && <p className="text-sm text-red-500">{formErrors.name}</p>}
      </div>

      {/* Strategy Selector */}
      <AlbsStrategySelector
        value={formData.strategy}
        onChange={(strategy) => onChange({ ...formData, strategy })}
      />

      {/* Members */}
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <Label>
            AI Assistant Members <span className="text-red-500">*</span>
            <span className="text-sm text-muted-foreground ml-2">
              ({formData.members?.length || 0} of {availableAiAssistants.length})
            </span>
          </Label>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onAddMember}
            disabled={
              (formData.members || []).length >= 50 ||
              availableAiAssistantsForMembers.length === 0
            }
            title={
              availableAiAssistantsForMembers.length === 0
                ? 'All available AI Assistants have been added'
                : 'Add another AI Assistant to the load balancer'
            }
          >
            <Plus className="h-4 w-4 mr-1" />
            Add Member
          </Button>
        </div>

        {formErrors.members && <p className="text-sm text-red-500">{formErrors.members}</p>}

        {availableAiAssistantsForMembers.length === 0 && formData.members?.length > 0 && (
          <Alert variant="default" className="bg-muted">
            <Info className="h-4 w-4" />
            <AlertDescription>
              All available AI Assistants have been added to this load balancer.
            </AlertDescription>
          </Alert>
        )}

        {(!formData.members || formData.members.length === 0) && (
          <div className="border rounded-lg p-8 text-center text-muted-foreground">
            <BotIcon className="h-8 w-8 mx-auto mb-2 opacity-50" />
            <p className="text-sm">No AI Assistants added yet</p>
            <p className="text-xs">
              {availableAiAssistantsForMembers.length > 0
                ? 'Click "Add Member" to add AI Assistants'
                : 'No available AI Assistants to add'}
            </p>
          </div>
        )}

        {formData.members && formData.members.length > 0 && formData.strategy === 'priority' && (
          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={onDragEnd}
          >
            <SortableContext
              items={formData.members.map((m) => m.ai_assistant_id)}
              strategy={verticalListSortingStrategy}
            >
              <div className="border rounded-lg divide-y">
                {formData.members.map((member) => (
                  <SortableItem key={member.ai_assistant_id} id={member.ai_assistant_id}>
                    {(dragHandleProps) => (
                      <div className="p-3 flex items-center gap-3">
                        <div {...dragHandleProps} className="cursor-grab hover:cursor-grabbing">
                          <GripVertical className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div className="flex-1">
                          <div className="flex items-center gap-2">
                            <BotIcon className="h-4 w-4 text-cyan-500" />
                            <span className="font-medium">{member.ai_assistant_name}</span>
                          </div>
                        </div>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          onClick={() => onRemoveMember(member.ai_assistant_id)}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                    )}
                  </SortableItem>
                ))}
              </div>
            </SortableContext>
          </DndContext>
        )}

        {formData.members && formData.members.length > 0 && formData.strategy !== 'priority' && (
          <div className="border rounded-lg divide-y">
            {formData.members.map((member) => (
              <div key={member.ai_assistant_id} className="p-3 flex items-center gap-3">
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <BotIcon className="h-4 w-4 text-cyan-500" />
                    <span className="font-medium">{member.ai_assistant_name}</span>
                  </div>
                </div>

                {formData.strategy === 'percentage' && (
                  <div className="flex items-center gap-2">
                    <Label className="text-xs">Weight:</Label>
                    <Select
                      value={member.weight.toString()}
                      onValueChange={(value) => {
                        const newMembers = [...formData.members];
                        const idx = newMembers.findIndex(
                          (m) => m.ai_assistant_id === member.ai_assistant_id
                        );
                        newMembers[idx] = { ...member, weight: parseInt(value) };
                        onChange({ ...formData, members: newMembers });
                      }}
                    >
                      <SelectTrigger className="w-24 h-8">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {WEIGHT_OPTIONS.map((weight) => (
                          <SelectItem key={weight} value={weight.toString()}>
                            {weight}%
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}

                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => onRemoveMember(member.ai_assistant_id)}
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
            ))}
          </div>
        )}

        {formData.strategy === 'percentage' && totalWeight > 0 && (
          <div className="text-sm text-muted-foreground">
            Total Distribution: {totalWeight}%
          </div>
        )}
      </div>

      {/* Follow Through Toggle */}
      <div className="space-y-4 pt-4 border-t">
        <div className="flex items-center justify-between">
          <div className="space-y-0.5">
            <Label>Follow Through</Label>
            <p className="text-sm text-muted-foreground">
              Try next AI Assistant if current one fails
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Label htmlFor="follow_through" className="text-sm text-muted-foreground">
              {formData.follow_through ? 'Enabled' : 'Disabled'}
            </Label>
            <Switch
              id="follow_through"
              checked={formData.follow_through}
              onCheckedChange={(checked) =>
                onChange({ ...formData, follow_through: checked })
              }
            />
          </div>
        </div>
        <Alert>
          <Info className="h-4 w-4" />
          <AlertDescription>
            When enabled, if an AI Assistant call fails, the load balancer will
            automatically try the next available assistant before executing the
            fallback action. When disabled, the fallback action is executed immediately
            when the first assistant fails.
          </AlertDescription>
        </Alert>
      </div>

      {/* Fallback Action */}
      <div className="space-y-2">
        <Label>
          Fallback Action <span className="text-red-500">*</span>
        </Label>
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor="fallback_action" className="text-sm text-muted-foreground">Action</Label>
            <Select
              value={formData.fallback_action}
              onValueChange={(value) => {
                onChange({
                  ...formData,
                  fallback_action: value as RingGroupFallbackAction,
                  fallback_extension_id:
                    value === 'extension' ? formData.fallback_extension_id : undefined,
                  fallback_ring_group_id:
                    value === 'ring_group' ? formData.fallback_ring_group_id : undefined,
                  fallback_ivr_menu_id:
                    value === 'ivr_menu' ? formData.fallback_ivr_menu_id : undefined,
                  fallback_ai_assistant_id:
                    value === 'ai_assistant' ? formData.fallback_ai_assistant_id : undefined,
                });
              }}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="extension">
                  <div className="flex items-center gap-2">
                    <PhoneForwarded className="h-4 w-4" />
                    <span>Extension</span>
                  </div>
                </SelectItem>
                <SelectItem value="ring_group">
                  <div className="flex items-center gap-2">
                    <Users className="h-4 w-4" />
                    <span>Ring Group</span>
                  </div>
                </SelectItem>
                <SelectItem value="ivr_menu">
                  <div className="flex items-center gap-2">
                    <Menu className="h-4 w-4" />
                    <span>IVR Menu</span>
                  </div>
                </SelectItem>
                <SelectItem value="ai_assistant">
                  <div className="flex items-center gap-2">
                    <BotIcon className="h-4 w-4" />
                    <span>AI Assistant</span>
                  </div>
                </SelectItem>
                <SelectItem value="hangup">
                  <div className="flex items-center gap-2">
                    <PhoneOff className="h-4 w-4" />
                    <span>Hangup</span>
                  </div>
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label className="text-sm text-muted-foreground">Destination</Label>
            {formData.fallback_action === 'extension' && (
              <Select
                value={formData.fallback_extension_id || ''}
                onValueChange={(value) =>
                  onChange({ ...formData, fallback_extension_id: value })
                }
                disabled={availableExtensions.length === 0}
              >
                <SelectTrigger>
                  <SelectValue
                    placeholder={
                      availableExtensions.length === 0
                        ? 'No Available Options for Extension'
                        : 'Select extension'
                    }
                  />
                </SelectTrigger>
                <SelectContent>
                  {availableExtensions.map((ext) => (
                    <SelectItem key={ext.id} value={ext.id.toString()}>
                      <div className="flex items-center gap-2">
                        <Badge
                          variant="outline"
                          className="flex items-center gap-1.5 bg-blue-100 text-blue-800 border-blue-200"
                        >
                          <Phone className="h-3.5 w-3.5" />
                          {ext.extension_number} - {ext.user?.name || 'Unassigned'}
                        </Badge>
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {formData.fallback_action === 'ring_group' && (
              <Select
                value={formData.fallback_ring_group_id || ''}
                onValueChange={(value) =>
                  onChange({ ...formData, fallback_ring_group_id: value })
                }
                disabled={availableRingGroups.length === 0}
              >
                <SelectTrigger>
                  <SelectValue
                    placeholder={
                      availableRingGroups.length === 0
                        ? 'No Available Options for Ring Group'
                        : 'Select ring group'
                    }
                  />
                </SelectTrigger>
                <SelectContent>
                  {availableRingGroups.map((group) => (
                    <SelectItem key={group.id} value={group.id.toString()}>
                      <div className="flex items-center gap-2">
                        <Badge
                          variant="outline"
                          className="flex items-center gap-1.5 bg-orange-100 text-orange-800 border-orange-200"
                        >
                          <Users className="h-3.5 w-3.5" />
                          {group.name}
                        </Badge>
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {formData.fallback_action === 'ivr_menu' && (
              <Select
                value={formData.fallback_ivr_menu_id || ''}
                onValueChange={(value) =>
                  onChange({ ...formData, fallback_ivr_menu_id: value })
                }
                disabled={availableIvrMenus.length === 0}
              >
                <SelectTrigger>
                  <SelectValue
                    placeholder={
                      availableIvrMenus.length === 0
                        ? 'No Available Options for IVR Menu'
                        : 'Select IVR menu'
                    }
                  />
                </SelectTrigger>
                <SelectContent>
                  {availableIvrMenus.map((menu) => (
                    <SelectItem key={menu.id} value={menu.id.toString()}>
                      <div className="flex items-center gap-2">
                        <Badge
                          variant="outline"
                          className="flex items-center gap-1.5 bg-purple-100 text-purple-800 border-purple-200"
                        >
                          <Menu className="h-3.5 w-3.5" />
                          {menu.name}
                        </Badge>
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {formData.fallback_action === 'ai_assistant' && (
              <Select
                value={formData.fallback_ai_assistant_id || ''}
                onValueChange={(value) =>
                  onChange({ ...formData, fallback_ai_assistant_id: value })
                }
                disabled={availableAiAssistants.length === 0}
              >
                <SelectTrigger>
                  <SelectValue
                    placeholder={
                      availableAiAssistants.length === 0
                        ? 'No Available Options for AI Assistant'
                        : 'Select AI assistant'
                    }
                  />
                </SelectTrigger>
                <SelectContent>
                  {availableAiAssistants.map((assistant) => (
                    <SelectItem key={assistant.id} value={String(assistant.id)}>
                      <div className="flex items-center gap-2">
                        <Badge
                          variant="outline"
                          className="flex items-center gap-1.5 bg-cyan-100 text-cyan-800 border-cyan-200"
                        >
                          <BotIcon className="h-3.5 w-3.5" />
                          {assistant.name}
                        </Badge>
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {formData.fallback_action === 'hangup' && (
              <div className="flex items-center h-10 px-3 border rounded-md bg-muted text-muted-foreground">
                No destination needed
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

export default LoadBalancerForm;
