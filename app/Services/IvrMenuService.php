<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IvrDestinationType;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Models\RingGroup;
use App\Models\ConferenceRoom;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Service class for IVR menu business logic and validation.
 */
class IvrMenuService
{
    /**
     * Validate IVR menu data including options.
     *
     * @param array $data
     * @param int|null $excludeMenuId Menu ID to exclude from unique validation
     * @return array Validated data
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateIvrMenuData(array $data, ?int $excludeMenuId = null): array
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audio_file_path' => 'nullable|string|max:500',
            'tts_text' => 'nullable|string|max:1000',
            'max_turns' => 'required|integer|min:1|max:9',
            'failover_destination_type' => ['required', Rule::in(IvrDestinationType::values())],
            'failover_destination_id' => [
                Rule::requiredIf(function () use ($data) {
                    return ($data['failover_destination_type'] ?? null) !== IvrDestinationType::HANGUP->value;
                }),
                'nullable',
                'integer'
            ],
            'status' => 'required|string|in:active,inactive',
            'options' => 'required|array|min:1|max:20',
            'options.*.input_digits' => [
                'required',
                'string',
                'max:10',
                'regex:/^[0-9*#]+$/',
                // Custom rule to ensure unique digits within the menu
                function ($attribute, $value, $fail) use ($data, $excludeMenuId) {
                    $this->validateUniqueDigits($value, $data['options'] ?? [], $attribute, $fail, $excludeMenuId);
                },
            ],
            'options.*.description' => 'nullable|string|max:255',
            'options.*.destination_type' => ['required', Rule::in(['extension', 'ring_group', 'conference_room', 'ivr_menu'])],
            'options.*.destination_id' => 'required|integer',
            'options.*.priority' => 'required|integer|min:1|max:20',
        ]);

        $validator->after(function ($validator) use ($data) {
            $this->validateDestinations($validator, $data);
            $this->validatePriorities($validator, $data);
        });

        return $validator->validate();
    }

    /**
     * Validate that destinations exist and are valid.
     */
    private function validateDestinations($validator, array $data): void
    {
        $organizationId = auth()->user()->organization_id ?? null;
        if (!$organizationId) {
            return;
        }

        foreach ($data['options'] ?? [] as $index => $option) {
            $destinationType = $option['destination_type'] ?? null;
            $destinationId = $option['destination_id'] ?? null;

            if (!$destinationType || !$destinationId) {
                continue;
            }

            $exists = match ($destinationType) {
                'extension' => Extension::where('id', $destinationId)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->exists(),
                'ring_group' => RingGroup::where('id', $destinationId)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->exists(),
                'conference_room' => ConferenceRoom::where('id', $destinationId)
                    ->where('organization_id', $organizationId)
                    ->exists(),
                'ivr_menu' => IvrMenu::where('id', $destinationId)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->when(isset($data['id']), fn($q) => $q->where('id', '!=', $data['id']))
                    ->exists(),
                default => false,
            };

            if (!$exists) {
                $validator->errors()->add(
                    "options.{$index}.destination_id",
                    "Selected destination does not exist or is not accessible."
                );
            }
        }

        // Validate failover destination
        if (isset($data['failover_destination_type']) && isset($data['failover_destination_id'])) {
            $failoverType = $data['failover_destination_type'];
            $failoverId = $data['failover_destination_id'];

            if ($failoverType !== 'hangup') {
                $exists = match ($failoverType) {
                    'extension' => Extension::where('id', $failoverId)
                        ->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->exists(),
                    'ring_group' => RingGroup::where('id', $failoverId)
                        ->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->exists(),
                    'conference_room' => ConferenceRoom::where('id', $failoverId)
                        ->where('organization_id', $organizationId)
                        ->exists(),
                    'ivr_menu' => IvrMenu::where('id', $failoverId)
                        ->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->when(isset($data['id']), fn($q) => $q->where('id', '!=', $data['id']))
                        ->exists(),
                    default => false,
                };

                if (!$exists) {
                    $validator->errors()->add(
                        'failover_destination_id',
                        'Selected failover destination does not exist or is not accessible.'
                    );
                }
            }
        }
    }

    /**
     * Validate that priorities are unique within the menu.
     */
    private function validatePriorities($validator, array $data): void
    {
        $priorities = [];
        foreach ($data['options'] ?? [] as $index => $option) {
            $priority = $option['priority'] ?? null;
            if ($priority !== null) {
                if (in_array($priority, $priorities)) {
                    $validator->errors()->add(
                        "options.{$index}.priority",
                        "Priority {$priority} is already used by another option."
                    );
                }
                $priorities[] = $priority;
            }
        }
    }

    /**
     * Validate that input digits are unique within the menu.
     */
    private function validateUniqueDigits(string $digits, array $options, string $attribute, callable $fail, ?int $excludeMenuId = null): void
    {
        $currentIndex = (int) str_replace(['options.', '.input_digits'], '', $attribute);

        foreach ($options as $index => $option) {
            if ($index === $currentIndex) {
                continue;
            }

            if (($option['input_digits'] ?? '') === $digits) {
                $fail("Input digits '{$digits}' are already used by another option in this menu.");
                return;
            }
        }

        // Also check against existing menu options in database (for updates)
        if ($excludeMenuId) {
            $exists = IvrMenuOption::where('ivr_menu_id', $excludeMenuId)
                ->where('input_digits', $digits)
                ->exists();

            if ($exists) {
                $fail("Input digits '{$digits}' are already used by another option in this menu.");
            }
        }
    }

    /**
     * Create a new IVR menu with options.
     *
     * @param array $data
     * @param int $organizationId
     * @return IvrMenu
     */
    public function createIvrMenu(array $data, int $organizationId): IvrMenu
    {
        $validatedData = $this->validateIvrMenuData($data);
        $optionsData = $validatedData['options'];
        unset($validatedData['options']);

        $validatedData['organization_id'] = $organizationId;

        $ivrMenu = IvrMenu::create($validatedData);

        foreach ($optionsData as $optionData) {
            $ivrMenu->options()->create($optionData);
        }

        return $ivrMenu->load('options');
    }

    /**
     * Update an existing IVR menu with options.
     *
     * @param IvrMenu $ivrMenu
     * @param array $data
     * @return IvrMenu
     */
    public function updateIvrMenu(IvrMenu $ivrMenu, array $data): IvrMenu
    {
        $validatedData = $this->validateIvrMenuData($data, $ivrMenu->id);
        $optionsData = $validatedData['options'];
        unset($validatedData['options']);

        $ivrMenu->update($validatedData);

        // Delete existing options and create new ones
        $ivrMenu->options()->delete();

        foreach ($optionsData as $optionData) {
            $ivrMenu->options()->create($optionData);
        }

        return $ivrMenu->load('options');
    }

    /**
     * Check if an IVR menu can be safely deleted.
     *
     * @param IvrMenu $ivrMenu
     * @return bool
     */
    public function canDeleteIvrMenu(IvrMenu $ivrMenu): bool
    {
        // Check if menu is referenced by other menus
        $referencedByMenus = IvrMenuOption::where('destination_type', IvrDestinationType::IVR_MENU->value)
            ->where('destination_id', $ivrMenu->id)
            ->exists();

        if ($referencedByMenus) {
            return false;
        }

        // Check if menu is referenced by DID routing
        $referencedByDid = \DB::table('did_numbers')
            ->where('routing_type', 'ivr_menu')
            ->where('routing_config->ivr_menu_id', $ivrMenu->id)
            ->exists();

        return !$referencedByDid;
    }

    /**
     * Get available destination options for dropdowns.
     *
     * @param int $organizationId
     * @return array
     */
    public function getAvailableDestinations(int $organizationId): array
    {
        return [
            'extensions' => Extension::where('organization_id', $organizationId)
                ->where('status', 'active')
                ->select('id', 'extension_number', 'name')
                ->get()
                ->map(fn($ext) => [
                    'id' => $ext->id,
                    'label' => "Ext {$ext->extension_number} - {$ext->name ?? 'Unassigned'}",
                ]),

            'ring_groups' => RingGroup::where('organization_id', $organizationId)
                ->where('status', 'active')
                ->select('id', 'name')
                ->get()
                ->map(fn($rg) => [
                    'id' => $rg->id,
                    'label' => "Ring Group: {$rg->name}",
                ]),

            'conference_rooms' => ConferenceRoom::where('organization_id', $organizationId)
                ->select('id', 'name')
                ->get()
                ->map(fn($cr) => [
                    'id' => $cr->id,
                    'label' => "Conference: {$cr->name}",
                ]),

            'ivr_menus' => IvrMenu::where('organization_id', $organizationId)
                ->where('status', 'active')
                ->select('id', 'name')
                ->get()
                ->map(fn($menu) => [
                    'id' => $menu->id,
                    'label' => "IVR Menu: {$menu->name}",
                ]),
        ];
    }
}