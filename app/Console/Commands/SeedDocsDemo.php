<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AlbsStatus;
use App\Enums\AlbsStrategy;
use App\Enums\BusinessHoursActionType;
use App\Enums\BusinessHoursExceptionType;
use App\Enums\BusinessHoursStatus;
use App\Enums\CallQueueStatus;
use App\Enums\CallQueueStrategy;
use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Enums\ExtensionType;
use App\Enums\InboundBlacklistMatchType;
use App\Enums\InboundBlacklistRejectionStrategy;
use App\Enums\InboundBlacklistStatus;
use App\Enums\IvrDestinationType;
use App\Enums\IvrMenuStatus;
use App\Enums\ListStatus;
use App\Enums\QueueCallDisposition;
use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Enums\RoutingDestinationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Models\ApiKey;
use App\Models\ApiKeyPermission;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\BusinessHoursException;
use App\Models\BusinessHoursExceptionTimeRange;
use App\Models\BusinessHoursSchedule;
use App\Models\BusinessHoursScheduleDay;
use App\Models\BusinessHoursTimeRange;
use App\Models\CallDetailRecord;
use App\Models\CallNotificationsSettings;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\InboundBlacklist;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\QueueCall;
use App\Models\Recording;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\SessionUpdate;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fills a self-contained, entirely fictional "Acme Corp (Demo)" organisation
 * used only to take documentation screenshots.
 *
 * Rows are created directly (no model events, no jobs, no HTTP), so nothing
 * reaches Cloudonix or any other external service. All data is invented:
 *
 * @example.com emails, +1 202 555 01xx numbers (fictional range), acme-demo.example.com domain.
 */
class SeedDocsDemo extends Command
{
    use ConfirmableTrait;

    public const SLUG = 'acme-corp-demo';

    public const DOMAIN = 'acme-demo.example.com';

    protected $signature = 'opbx:seed-docs-demo
        {--fresh : Remove the existing demo organisation first and recreate it}
        {--remove : Delete the demo organisation and everything that belongs to it}
        {--force : Skip the confirmation prompt outside local environments}';

    protected $description = 'Create (or --remove) a fictional "Acme Corp (Demo)" organisation for documentation screenshots';

    private int $org;

    /** @var array<string, int> */
    private array $u = [];

    /** @var array<string, int> */
    private array $x = [];

    /** @var array<string, int> */
    private array $id = [];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to create demo data in production.');

            return self::FAILURE;
        }

        $existing = Organization::withTrashed()->where('slug', self::SLUG)->first();

        if ($this->option('remove')) {
            return $this->remove($existing);
        }

        if ($existing !== null) {
            if (! $this->option('fresh')) {
                $this->info("Demo organisation already exists (id {$existing->id}). Use --fresh to recreate it.");

                return self::SUCCESS;
            }
            if ($this->remove($existing) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        mt_srand(2026);
        $counts = Model::withoutEvents(fn () => OrganizationScope::bypass(
            fn () => Model::unguarded(fn () => DB::transaction(fn () => $this->seed()))
        ));

        $this->info("Created demo organisation 'Acme Corp (Demo)' (id {$this->org}):");
        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-34s %d', $table, $count));
        }

        return self::SUCCESS;
    }

    private function remove(?Organization $org): int
    {
        if ($org === null) {
            $this->info('No demo organisation to remove.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed(
            "This permanently deletes organisation {$org->id} (".self::SLUG.') and all its data.',
            fn () => ! app()->environment('local', 'testing'),
        )) {
            return self::FAILURE;
        }

        $removed = DB::transaction(function () use ($org): array {
            $removed = $this->rowCounts($org->id);
            // Not every organization_id FK cascades (e.g. ivr_menus), so clear the tables
            // explicitly; passes repeat until FK ordering between them is resolved.
            for ($pass = 0, $left = $removed; $left !== [] && $pass < 6; $pass++) {
                foreach (array_keys($left) as $table) {
                    try {
                        DB::table($table)->where('organization_id', $org->id)->delete();
                        unset($left[$table]);
                    } catch (QueryException) {
                        // a dependent table still references these rows; retry next pass
                    }
                }
            }
            // Remaining dependents (menu options, members, ...) cascade from their parents.
            Organization::withTrashed()->whereKey($org->id)->forceDelete();

            return $removed;
        });

        $this->info("Removed demo organisation id {$org->id}:");
        foreach ($removed as $table => $count) {
            $this->line(sprintf('  %-34s %d', $table, $count));
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> non-empty per-table row counts for an organisation */
    private function rowCounts(int $orgId): array
    {
        $counts = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table !== 'organizations' && Schema::hasColumn($table, 'organization_id')) {
                $n = DB::table($table)->where('organization_id', $orgId)->count();
                if ($n > 0) {
                    $counts[$table] = $n;
                }
            }
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function seed(): array
    {
        $org = Organization::create([
            'name' => 'Acme Corp (Demo)',
            'slug' => self::SLUG,
            'status' => 'active',
            'timezone' => 'America/New_York',
            'settings' => [],
        ]);
        $this->org = $org->id;

        $this->users();
        $this->recordings();
        $this->aiAssistants();
        $this->extensions();
        $this->conferenceRooms();
        $this->ringGroups();
        $this->queues();
        $this->ivrMenus();
        $this->businessHours();
        $this->dids();
        $this->security();
        $this->callTracking();
        $this->autoDialer();
        $this->calls();

        return $this->rowCounts($this->org);
    }

    private function users(): void
    {
        $hash = Hash::make(Str::random(40)); // nobody is meant to log in as a demo user
        $rows = [
            ['alice', 'Alice Johnson', UserRole::OWNER, UserStatus::ACTIVE],
            ['brian', 'Brian Carter', UserRole::PBX_ADMIN, UserStatus::ACTIVE],
            ['carla', 'Carla Mendes', UserRole::SUPERVISOR, UserStatus::ACTIVE],
            ['daniel', 'Daniel Kim', UserRole::REPORTER, UserStatus::ACTIVE],
            ['emma', 'Emma Wilson', UserRole::PBX_USER, UserStatus::ACTIVE],
            ['frank', 'Frank Ortiz', UserRole::PBX_USER, UserStatus::ACTIVE],
            ['grace', 'Grace Lee', UserRole::PBX_USER, UserStatus::ACTIVE],
            ['henry', 'Henry Brooks', UserRole::PBX_USER, UserStatus::ACTIVE],
            ['isabel', 'Isabel Novak', UserRole::PBX_USER, UserStatus::ACTIVE],
            ['jack', 'Jack Turner', UserRole::PBX_USER, UserStatus::ACTIVE],
            ['karen', 'Karen Patel', UserRole::PBX_USER, UserStatus::INACTIVE],
        ];
        foreach ($rows as $i => [$key, $name, $role, $status]) {
            $this->u[$key] = User::create([
                'organization_id' => $this->org,
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
                'email_verified_at' => now(),
                'password' => $hash,
                'role' => $role,
                'status' => $status,
                'phone' => '+12025550'.(110 + $i),
            ])->id;
        }

        $rows = [['carla', 'emma'], ['carla', 'frank'], ['carla', 'grace']];
        foreach ($rows as [$sup, $usr]) {
            DB::table('supervisor_user_assignments')->insert([
                'supervisor_id' => $this->u[$sup], 'user_id' => $this->u[$usr],
                'organization_id' => $this->org, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function recordings(): void
    {
        foreach ([
            ['welcome', 'Welcome Greeting', false],
            ['afterhours', 'After Hours Message', false],
            ['moh', 'Hold Music - Smooth Jazz', true],
        ] as [$key, $name, $moh]) {
            // Remote recordings: metadata only, no stored file is needed to render the page.
            $this->id['rec_'.$key] = Recording::create([
                'organization_id' => $this->org, 'name' => $name, 'type' => 'remote',
                'remote_url' => 'https://media.acme-demo.example.com/audio/'.Str::slug($name).'.mp3',
                'original_filename' => Str::slug($name).'.mp3', 'mime_type' => 'audio/mpeg',
                'file_size' => 480000, 'duration_seconds' => 24, 'status' => 'active',
                'is_moh' => $moh, 'created_by' => $this->u['alice'], 'updated_by' => $this->u['alice'],
            ])->id;
        }
    }

    private function aiAssistants(): void
    {
        foreach ([
            ['aria', 'Aria - Front Desk Receptionist', 'vapi', '+12025550170'],
            ['max', 'Max - Support Triage', 'retell', '+12025550171'],
        ] as [$key, $name, $provider, $phone]) {
            $this->id['ai_'.$key] = AiAssistant::create([
                'organization_id' => $this->org, 'name' => $name, 'status' => 'active',
                'description' => 'Fictional demo assistant', 'provider' => $provider, 'protocol' => 'sip',
                'configuration' => ['phone_number' => $phone],
                'created_by' => $this->u['alice'], 'updated_by' => $this->u['alice'],
            ])->id;
        }

        $alb = AiAssistantLoadBalancer::create([
            'organization_id' => $this->org, 'name' => 'Front Desk AI Pool',
            'description' => 'Spreads inbound AI calls across both assistants',
            'strategy' => AlbsStrategy::ROUND_ROBIN, 'status' => AlbsStatus::ACTIVE,
            'fallback_action' => RingGroupFallbackAction::HANGUP,
            'created_by' => $this->u['alice'], 'updated_by' => $this->u['alice'],
        ]);
        $this->id['alb'] = $alb->id;
        foreach (['aria', 'max'] as $pos => $key) {
            AiAssistantLoadBalancerMember::create([
                'load_balancer_id' => $alb->id, 'ai_assistant_id' => $this->id['ai_'.$key],
                'priority' => $pos, 'weight' => 50, 'position' => $pos, 'status' => 'active',
            ]);
        }
    }

    private function extensions(): void
    {
        $users = ['alice', 'brian', 'carla', 'daniel', 'emma', 'frank', 'grace', 'henry', 'isabel', 'jack', 'karen'];
        foreach ($users as $i => $key) {
            $this->extension((string) (1001 + $i), ExtensionType::USER, [
                'user_id' => $this->u[$key],
                'status' => $key === 'karen' ? UserStatus::INACTIVE : UserStatus::ACTIVE,
                'voicemail_enabled' => $i % 2 === 0,
                'configuration' => ['sip_uri' => 'sip:'.(1001 + $i).'@'.self::DOMAIN],
            ], $key);
        }
        $this->extension('1012', ExtensionType::FORWARD, ['configuration' => ['forward_to' => '+12025550180']], 'forward');
        $this->extension('1013', ExtensionType::AI_ASSISTANT, [
            'ai_assistant_id' => $this->id['ai_aria'],
            'configuration' => ['ai_assistant_id' => $this->id['ai_aria']],
        ], 'aria');
    }

    /** @param array<string, mixed> $attrs */
    private function extension(string $number, ExtensionType $type, array $attrs, string $key): void
    {
        $this->x[$key] = Extension::create($attrs + [
            'organization_id' => $this->org, 'extension_number' => $number, 'type' => $type,
            'status' => UserStatus::ACTIVE, 'voicemail_enabled' => false,
            'password' => Str::random(20), 'cloudonix_subscriber_id' => (string) (9000 + (int) $number % 1000),
            'cloudonix_uuid' => (string) Str::uuid(), 'cloudonix_synced' => true,
        ])->id;
    }

    private function conferenceRooms(): void
    {
        $this->id['conf_all'] = ConferenceRoom::create([
            'organization_id' => $this->org, 'name' => 'Weekly All-Hands',
            'description' => 'Company-wide Monday stand-up', 'max_participants' => 50,
            'status' => UserStatus::ACTIVE, 'pin' => '4821', 'pin_required' => true, 'host_pin' => '9034',
            'recording_enabled' => true, 'recording_auto_start' => false, 'wait_for_host' => true,
            'mute_on_entry' => true, 'announce_join_leave' => true, 'music_on_hold' => true,
        ])->id;
        $this->id['conf_esc'] = ConferenceRoom::create([
            'organization_id' => $this->org, 'name' => 'Support Escalation Bridge',
            'description' => 'Open bridge for priority incidents', 'max_participants' => 15,
            'status' => UserStatus::ACTIVE, 'pin_required' => false,
        ])->id;
    }

    private function ringGroups(): void
    {
        foreach ([
            ['sales', 'Sales Team', RingGroupStrategy::SIMULTANEOUS, ['emma', 'frank', 'grace'], RingGroupFallbackAction::AI_ASSISTANT, ['fallback_ai_assistant_id' => $this->x['aria']]],
            ['support', 'Support Team', RingGroupStrategy::SEQUENTIAL, ['henry', 'isabel', 'jack'], RingGroupFallbackAction::EXTENSION, ['fallback_extension_id' => $this->x['forward']]],
            ['exec', 'Executive Line', RingGroupStrategy::ROUND_ROBIN, ['alice', 'brian'], RingGroupFallbackAction::UNAVAILABLE, []], // shows the Unprovisioned badge
        ] as [$key, $name, $strategy, $members, $fallback, $fallbackAttrs]) {
            $rg = RingGroup::create([
                'organization_id' => $this->org, 'name' => $name, 'description' => $name.' (demo)',
                'strategy' => $strategy, 'timeout' => 25, 'ring_turns' => 2,
                'fallback_action' => $fallback, 'status' => RingGroupStatus::ACTIVE,
            ] + $fallbackAttrs);
            $this->id['rg_'.$key] = $rg->id;
            foreach ($members as $priority => $m) {
                RingGroupMember::create(['ring_group_id' => $rg->id, 'extension_id' => $this->x[$m], 'priority' => $priority + 1]);
            }
        }

        foreach (['carla' => ['sales', 'support']] as $sup => $groups) {
            foreach ($groups as $g) {
                DB::table('supervisor_ring_group_assignments')->insert([
                    'supervisor_id' => $this->u[$sup], 'ring_group_id' => $this->id['rg_'.$g],
                    'organization_id' => $this->org, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function queues(): void
    {
        foreach ([
            ['support', 'Support Queue', CallQueueStrategy::RING_ALL, ['henry', 'isabel', 'jack'], ['fallback_action' => RingGroupFallbackAction::RING_GROUP, 'fallback_ring_group_id' => $this->id['rg_support']]],
            ['billing', 'Billing Queue', CallQueueStrategy::ROUND_ROBIN, ['emma', 'grace'], ['fallback_action' => RingGroupFallbackAction::EXTENSION, 'fallback_extension_id' => $this->x['forward']]],
        ] as [$key, $name, $strategy, $agents, $fallback]) {
            $q = CallQueue::create([
                'organization_id' => $this->org, 'name' => $name, 'description' => $name.' (demo)',
                'strategy' => $strategy, 'agent_ring_timeout' => 20, 'max_wait_seconds' => 300,
                'wrap_up_seconds' => 15, 'announce_position' => true, 'announce_position_timeout' => 60,
                'announce_position_language' => 'en', 'immediate_connect' => false,
                'conference_ring_all' => false, 'status' => CallQueueStatus::ACTIVE,
            ] + $fallback);
            $this->id['q_'.$key] = $q->id;
            foreach ($agents as $a) {
                CallQueueAgent::create(['organization_id' => $this->org, 'call_queue_id' => $q->id, 'user_id' => $this->u[$a]]);
            }
        }
    }

    private function ivrMenus(): void
    {
        $main = IvrMenu::create([
            'organization_id' => $this->org, 'name' => 'Main Menu', 'description' => 'Greets daytime callers',
            'tts_text' => 'Thank you for calling Acme Corp. For sales press 1, for support press 2, for billing press 3, or press 0 for the operator.',
            'tts_voice' => 'en-US-Neural2-A', 'max_turns' => 3,
            'failover_destination_type' => IvrDestinationType::EXTENSION->value,
            'failover_destination_id' => $this->x['alice'], 'status' => IvrMenuStatus::ACTIVE->value,
        ]);
        $after = IvrMenu::create([
            'organization_id' => $this->org, 'name' => 'After Hours Menu', 'description' => 'Plays a recorded closed message',
            'recording_id' => $this->id['rec_afterhours'], 'max_turns' => 2,
            'failover_destination_type' => IvrDestinationType::HANGUP->value, 'status' => IvrMenuStatus::ACTIVE->value,
        ]);
        $this->id['ivr_main'] = $main->id;
        $this->id['ivr_after'] = $after->id;

        $options = [
            [$main, '1', 'Sales', IvrDestinationType::RING_GROUP, $this->id['rg_sales']],
            [$main, '2', 'Support', IvrDestinationType::RING_GROUP, $this->id['rg_support']],
            [$main, '3', 'Billing', IvrDestinationType::CALL_QUEUE, $this->id['q_billing']],
            [$main, '0', 'Operator', IvrDestinationType::EXTENSION, $this->x['alice']],
            [$after, '1', 'Leave a message', IvrDestinationType::EXTENSION, $this->x['forward']],
            [$after, '2', 'Talk to our AI receptionist', IvrDestinationType::AI_ASSISTANT, $this->id['ai_aria']],
        ];
        foreach ($options as $i => [$menu, $digits, $desc, $type, $target]) {
            IvrMenuOption::create([
                'ivr_menu_id' => $menu->id, 'input_digits' => $digits, 'description' => $desc,
                'destination_type' => $type, 'destination_id' => $target, 'priority' => $i + 1,
            ]);
        }
    }

    private function businessHours(): void
    {
        $year = now()->year;
        foreach ([
            ['office', 'Main Office Hours', 'ivr-'.$this->id['ivr_main'], BusinessHoursActionType::IVR_MENU, 'ivr-'.$this->id['ivr_after'], BusinessHoursActionType::IVR_MENU,
                ['monday' => ['09:00', '17:00'], 'tuesday' => ['09:00', '17:00'], 'wednesday' => ['09:00', '17:00'], 'thursday' => ['09:00', '17:00'], 'friday' => ['09:00', '16:00'], 'saturday' => ['10:00', '14:00']]],
            ['support', 'Support Desk Hours', 'rg-'.$this->id['rg_support'], BusinessHoursActionType::RING_GROUP, 'ext-'.$this->x['forward'], BusinessHoursActionType::EXTENSION,
                ['monday' => ['08:00', '20:00'], 'tuesday' => ['08:00', '20:00'], 'wednesday' => ['08:00', '20:00'], 'thursday' => ['08:00', '20:00'], 'friday' => ['08:00', '20:00']]],
            // Closed action disconnected: shows the Unprovisioned badge.
            ['holiday', 'Holiday Hotline Hours', 'rg-'.$this->id['rg_sales'], BusinessHoursActionType::RING_GROUP, 'unavailable', BusinessHoursActionType::UNAVAILABLE,
                ['saturday' => ['10:00', '16:00'], 'sunday' => ['10:00', '16:00']]],
        ] as [$key, $name, $open, $openType, $closed, $closedType, $days]) {
            $s = BusinessHoursSchedule::create([
                'organization_id' => $this->org, 'name' => $name, 'status' => BusinessHoursStatus::ACTIVE,
                'open_hours_action' => ['target_id' => $open], 'open_hours_action_type' => $openType,
                'closed_hours_action' => ['target_id' => $closed], 'closed_hours_action_type' => $closedType,
            ]);
            $this->id['bh_'.$key] = $s->id;
            foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
                $d = BusinessHoursScheduleDay::create([
                    'business_hours_schedule_id' => $s->id, 'day_of_week' => $day, 'enabled' => isset($days[$day]),
                ]);
                if (isset($days[$day])) {
                    BusinessHoursTimeRange::create([
                        'business_hours_schedule_day_id' => $d->id,
                        'start_time' => $days[$day][0].':00', 'end_time' => $days[$day][1].':00',
                    ]);
                }
            }
            foreach ([
                ["$year-12-24", 'Christmas Eve (early close)', BusinessHoursExceptionType::SPECIAL_HOURS],
                ["$year-12-25", 'Christmas Day', BusinessHoursExceptionType::CLOSED],
                [($year + 1).'-01-01', "New Year's Day", BusinessHoursExceptionType::CLOSED],
            ] as [$date, $label, $type]) {
                $e = BusinessHoursException::create([
                    'business_hours_schedule_id' => $s->id, 'date' => $date, 'name' => $label, 'type' => $type,
                ]);
                if ($type === BusinessHoursExceptionType::SPECIAL_HOURS) {
                    BusinessHoursExceptionTimeRange::create([
                        'business_hours_exception_id' => $e->id, 'start_time' => '09:00:00', 'end_time' => '13:00:00',
                    ]);
                }
            }
        }
    }

    private function dids(): void
    {
        $rows = [
            ['main', '+12025550100', 'Main Line', 'business_hours', ['business_hours_schedule_id' => $this->id['bh_office']]],
            ['sales', '+12025550101', 'Sales Direct', 'ring_group', ['ring_group_id' => $this->id['rg_sales']]],
            ['support', '+12025550102', 'Support Line', 'call_queue', ['call_queue_id' => $this->id['q_support']]],
            ['conf', '+12025550103', 'Conference Bridge', 'conference_room', ['conference_room_id' => $this->id['conf_all']]],
            ['ai', '+12025550104', 'AI Receptionist', 'ai_assistant', ['ai_assistant_id' => $this->id['ai_aria']]],
            ['future', '+12025550105', 'Spring Campaign (not yet assigned)', 'unavailable', []],
            ['alice', '+12025550106', 'Alice Direct', 'extension', ['extension_id' => $this->x['alice']]],
            ['ivr', '+12025550107', 'Customer Menu', 'ivr_menu', ['ivr_menu_id' => $this->id['ivr_main']]],
        ];
        foreach ($rows as [$key, $number, $name, $type, $config]) {
            $this->id['did_'.$key] = DidNumber::create([
                'organization_id' => $this->org, 'phone_number' => $number, 'friendly_name' => $name,
                'routing_type' => $type, 'routing_config' => $config, 'status' => 'active',
            ])->id;
        }

        Extension::where('organization_id', $this->org)
            ->whereIn('id', [$this->x['alice'], $this->x['emma'], $this->x['henry']])
            ->update(['default_caller_id_did_id' => $this->id['did_main']]);
    }

    private function security(): void
    {
        foreach ([
            ['US and Canada', 'US', '+1', 'acme-demo-trunk-us'],
            ['United Kingdom', 'GB', '+44', 'acme-demo-trunk-eu'],
            ['Germany', 'DE', '+49', 'acme-demo-trunk-eu'],
            ['Israel', 'IL', '+972', 'acme-demo-trunk-il'],
        ] as [$name, $country, $prefix, $trunk]) {
            OutboundWhitelist::create([
                'organization_id' => $this->org, 'name' => $name, 'destination_country' => $country,
                'destination_prefix' => $prefix, 'outbound_trunk_name' => $trunk, 'status' => 'active',
                'default_caller_id_did_id' => $this->id['did_main'],
            ]);
        }

        foreach ([
            [InboundBlacklistMatchType::EXACT, '+12025550190', InboundBlacklistRejectionStrategy::DROP, 14],
            [InboundBlacklistMatchType::EXACT, '+12025550191', InboundBlacklistRejectionStrategy::REJECT, 6],
            [InboundBlacklistMatchType::PREFIX, '+1202555019', InboundBlacklistRejectionStrategy::TORMENT, 31],
            [InboundBlacklistMatchType::WILDCARD, '+1202555018*', InboundBlacklistRejectionStrategy::DROP, 3],
        ] as [$match, $pattern, $strategy, $blocked]) {
            InboundBlacklist::create([
                'organization_id' => $this->org, 'match_type' => $match, 'caller_id_pattern' => $pattern,
                'is_global' => true, 'rejection_strategy' => $strategy,
                'torment_room_prefix' => $strategy === InboundBlacklistRejectionStrategy::TORMENT ? 'demo' : null,
                'torment_music_timeout' => 300, 'status' => InboundBlacklistStatus::ACTIVE, 'blocked_count' => $blocked,
            ]);
        }

        foreach ([
            ['Reporting Dashboard', ['call-detail-records' => 'read', 'dashboard' => 'read']],
            ['CRM Integration', ['users' => 'read', 'extensions' => 'write', 'phone-numbers' => 'read']],
        ] as [$name, $perms]) {
            $key = ApiKey::create([
                'organization_id' => $this->org, 'name' => $name,
                'token' => hash('sha256', 'demo_'.Str::random(48)), // random, never shown; the key is unrecoverable
                'created_by' => $this->u['alice'], 'last_used_at' => now()->subHours(random_int(2, 72)),
            ]);
            foreach ($perms as $resource => $level) {
                ApiKeyPermission::create(['api_key_id' => $key->id, 'resource' => $resource, 'level' => $level]);
            }
        }

        CallNotificationsSettings::create([
            'organization_id' => $this->org, 'webhook_url' => 'https://hooks.acme-demo.example.com/opbx',
            'auth_method' => 'bearer_token', 'auth_secret' => Str::random(40),
            'retry_attempts' => 3, 'retry_backoff_seconds' => 60, 'request_timeout_seconds' => 30,
            'enabled_events' => ['new', 'ringing', 'connected', 'answered'],
            'rate_limit_per_minute' => 500, 'is_active' => true,
        ]);
    }

    private function callTracking(): void
    {
        $campaigns = [
            ['Fall Promo - Search Ads', 'google', 'cpc', ['ring_group_id' => $this->id['rg_sales']], CallTrackingDestinationType::RING_GROUP],
            ['Partner Newsletter', 'newsletter', 'email', ['forward_to' => '+12025550180'], CallTrackingDestinationType::FORWARD],
        ];
        // Tracking numbers get their own DIDs, routed to the tracking campaign.
        foreach ($campaigns as $i => [$name, $source, $medium, $config, $type]) {
            $c = CallTrackingCampaign::create([
                'organization_id' => $this->org, 'name' => $name, 'source' => $source, 'medium' => $medium,
                'description' => 'Fictional demo campaign', 'status' => CallTrackingCampaignStatus::ACTIVE,
                'destination_type' => $type, 'destination_config' => $config,
                'conversion_rule' => ['min_answered_duration_seconds' => 60, 'requires_answered_disposition' => true],
                'google_ads_upload_enabled' => false, 'meta_upload_enabled' => false,
            ]);
            $did = DidNumber::create([
                'organization_id' => $this->org, 'phone_number' => '+1202555011'.($i + 5),
                'friendly_name' => $name.' tracking line', 'routing_type' => 'call_tracking',
                'routing_config' => ['call_tracking_campaign_id' => $c->id], 'status' => 'active',
            ]);
            $number = CallTrackingNumber::create([
                'organization_id' => $this->org, 'call_tracking_campaign_id' => $c->id, 'did_number_id' => $did->id,
                'friendly_name' => $name.' line', 'status' => CallTrackingCampaignStatus::ACTIVE,
            ]);

            for ($n = 0; $n < 14; $n++) {
                $start = now()->subDays(random_int(0, 29))->subMinutes(random_int(60, 600));
                $billsec = random_int(0, 3) === 0 ? 0 : random_int(15, 280);
                $duration = $billsec > 0 ? $billsec + random_int(3, 12) : random_int(5, 30);
                $disp = $billsec > 0 ? 'CONNECTED' : 'NO ANSWER';
                $caller = '+12025550'.random_int(120, 199);
                CallTrackingSession::create([
                    'organization_id' => $this->org, 'call_tracking_campaign_id' => $c->id,
                    'call_tracking_number_id' => $number->id, 'did_number_id' => $did->id,
                    'call_id' => (string) Str::uuid(), 'session_id' => (string) Str::uuid(),
                    'caller_number' => $caller, 'caller_country' => 'US', 'called_number' => $did->phone_number,
                    'source' => $source, 'medium' => $medium, 'campaign_name' => $name,
                    'disposition' => $disp, 'duration' => $duration, 'billsec' => $billsec,
                    'is_answered' => $billsec > 0, 'is_converted' => $billsec >= 60 && random_int(0, 2) === 0,
                    'started_at' => $start, 'answered_at' => $billsec > 0 ? $start->copy()->addSeconds(6) : null,
                    'ended_at' => $start->copy()->addSeconds($duration),
                    'raw_cdr' => ['from' => $caller, 'to' => $did->phone_number, 'disposition' => $disp],
                ]);
            }
        }
    }

    private function autoDialer(): void
    {
        $campaign = AutoDialerCampaign::create([
            'organization_id' => $this->org, 'name' => 'Fall Webinar Follow-up',
            'description' => 'Fictional outbound campaign (not running)', 'status' => CampaignStatus::PAUSED,
            'auto_start' => false, 'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => $this->id['ai_aria'], 'dial_timeout' => 45,
            'destination_connect' => 'connected', 'caller_id' => '+12025550100', 'max_dial_attempts' => 3,
            'concurrent_active_calls' => 3, 'days_active' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'start_time' => 9, 'end_time' => 17, 'start_date' => now()->startOfDay(),
            'end_date' => now()->addDays(30), 'timezone' => 'America/New_York', 'time_limit' => 1800,
            'record_calls' => false, 'retry_on_voicemail' => false, 'voicemail_calls' => 0,
            'total_destinations' => 20, 'completed_calls' => 0, 'failed_calls' => 0, 'pending_calls' => 20,
        ]);

        $list = AutoDialerList::create([
            'organization_id' => $this->org, 'campaign_id' => $campaign->id, 'name' => 'Fall Webinar Leads',
            'description' => 'Attendees of the October webinar', 'version_number' => 1, 'is_latest_version' => true,
            'status' => ListStatus::READY, 'original_filename' => 'webinar-leads.csv', 'processed_at' => now(),
            'total_rows' => 20, 'valid_rows' => 20, 'invalid_rows' => 0,
        ]);

        $names = ['Liam Foster', 'Olivia Reed', 'Noah Bennett', 'Ava Morgan', 'Elijah Price', 'Sophia Hayes', 'Lucas Ward', 'Mia Cooper', 'Mason Gray', 'Amelia Ross',
            'Ethan Bell', 'Harper Cole', 'Logan Fox', 'Ella Hunt', 'James Dean', 'Chloe West', 'Owen Pierce', 'Zoe Grant', 'Caleb Stone', 'Lily Marsh'];
        foreach ($names as $i => $name) {
            AutoDialerDestination::create([
                'organization_id' => $this->org, 'list_id' => $list->id, 'phone_number' => '+12025550'.(140 + $i),
                'name' => $name, 'status' => DestinationStatus::PENDING, 'dial_attempts' => 0,
                'duration' => 0, 'billsec' => 0, 'total_duration' => 0,
            ]);
        }
    }

    private function calls(): void
    {
        $ext = ['1001', '1002', '1005', '1006', '1007', '1008', '1009', '1010'];
        // CDR.subscriber carries the extension's Cloudonix UUID (the CallDetailRecord::extension() key), not its number.
        $uuids = Extension::withoutGlobalScopes()->where('organization_id', $this->org)->pluck('cloudonix_uuid', 'extension_number');
        $dids = ['+12025550100', '+12025550101', '+12025550102', '+12025550104', '+12025550107'];
        $dispositions = ['CONNECTED', 'CONNECTED', 'CONNECTED', 'CONNECTED', 'CONNECTED', 'NO_ANSWER', 'NO_ANSWER', 'BUSY', 'FAILED'];

        for ($i = 0; $i < 150; $i++) {
            $at = now()->subDays(random_int(0, 29))->setTime(random_int(8, 18), random_int(0, 59), random_int(0, 59));
            if ($at->isFuture()) {
                $at->subDay();
            }
            $incoming = random_int(0, 99) < 62;
            $disposition = $dispositions[array_rand($dispositions)];
            $billsec = $disposition === 'CONNECTED' ? random_int(12, 900) : 0;
            $external = '+12025550'.random_int(120, 199);
            $mine = $ext[array_rand($ext)];
            CallDetailRecord::create([
                'organization_id' => $this->org, 'session_timestamp' => $at, 'session_token' => (string) Str::uuid(),
                'from' => $incoming ? $external : $mine, 'to' => $incoming ? $dids[array_rand($dids)] : $external,
                'direction' => $incoming ? 'incoming' : 'outgoing', 'disposition' => $disposition,
                'duration' => $billsec + ($disposition === 'CONNECTED' ? random_int(2, 12) : random_int(5, 45)),
                'billsec' => $billsec, 'call_id' => (string) Str::uuid(), 'domain' => self::DOMAIN,
                'subscriber' => $uuids[$mine] ?? null, 'session_id' => 700000 + $i, 'raw_cdr' => [],
            ]);
        }

        // Live Calls: latest row per session within the last 30 minutes.
        foreach ([
            ['connected', 'incoming', '+12025550131', '1005', 9005],
            ['connected', 'outgoing', '1006', '+12025550162', 9006],
            ['ringing', 'incoming', '+12025550177', '1008', 9008],
        ] as $i => [$status, $direction, $from, $to, $subscriber]) {
            SessionUpdate::create([
                'organization_id' => $this->org, 'session_id' => 880000 + $i, 'session_token' => (string) Str::uuid(),
                'event_id' => (string) Str::uuid(), 'domain_id' => 1, 'domain' => self::DOMAIN,
                'subscriber_id' => $subscriber, 'caller_id' => $from, 'destination' => $to,
                'direction' => $direction, 'status' => $status, 'session_created_at' => now()->subMinutes(5 + $i),
                'session_modified_at' => now(), 'start_time' => now()->subMinutes(5 + $i),
                'answer_time' => $status === 'connected' ? now()->subMinutes(4 + $i) : null,
                'time_limit' => 3600, 'action' => 'none', 'reason' => 'normal', 'call_ids' => [],
                'profile' => ['callerId' => $from, 'destination' => $to], 'processed_at' => now(),
            ]);
        }

        $agents = [
            $this->id['q_support'] => ['henry', 'isabel', 'jack'],
            $this->id['q_billing'] => ['emma', 'grace'],
        ];
        for ($i = 0; $i < 70; $i++) {
            $queueId = $i % 3 === 0 ? $this->id['q_billing'] : $this->id['q_support'];
            $entered = now()->subDays(random_int(0, 13))->setTime(random_int(8, 18), random_int(0, 59), random_int(0, 59));
            if ($entered->isFuture()) {
                $entered->subDay();
            }
            $roll = random_int(0, 99);
            $disposition = $roll < 72 ? QueueCallDisposition::ANSWERED : ($roll < 92 ? QueueCallDisposition::ABANDONED : QueueCallDisposition::OVERFLOW);
            $wait = random_int(4, 150);
            $handle = $disposition === QueueCallDisposition::ANSWERED ? random_int(45, 620) : null;
            $row = [
                'organization_id' => $this->org, 'call_queue_id' => $queueId, 'call_id' => 'demo-call-'.$i,
                'session_token' => (string) Str::uuid(), 'from_number' => '+12025550'.random_int(120, 199),
                'to_number' => '+12025550102', 'entered_at' => $entered, 'waiting_seconds' => $wait,
                'disposition' => $disposition, 'handling_seconds' => $handle,
                'ended_at' => $entered->copy()->addSeconds($wait + ($handle ?? 0)),
            ];
            if ($disposition === QueueCallDisposition::ANSWERED) {
                $row['answered_at'] = $entered->copy()->addSeconds($wait);
                $row['agent_user_id'] = $this->u[$agents[$queueId][array_rand($agents[$queueId])]];
            } elseif ($disposition === QueueCallDisposition::ABANDONED) {
                $row['abandoned_at'] = $entered->copy()->addSeconds($wait);
            }
            QueueCall::create($row);
        }
    }
}
