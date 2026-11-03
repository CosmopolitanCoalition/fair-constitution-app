<?php

namespace App\Support;

/**
 * Catalog behind the node surfaces: the datastores a person owns, the audiences that may see
 * them, the bundled tools a box can add, the outside services worth connecting, and the
 * inventory ported from the Jarvis assistant (tools, safety classes, surface levels, brains).
 *
 * This is design data, not user data. A row says what exists or what is offered. A person's own
 * choices (who sees what, which bundles are on) are stored per owner and joined in the controller
 * once the wiring items land. Status values: built, partial, planned.
 */
class NodeCatalog
{
    /** Rings from the who-sees-what design, nearest first. */
    public const AUDIENCES = [
        ['id' => 'me', 'label' => 'Only me'],
        ['id' => 'contact', 'label' => 'One contact'],
        ['id' => 'household', 'label' => 'Household'],
        ['id' => 'organization', 'label' => 'Organization'],
        ['id' => 'community', 'label' => 'Community'],
        ['id' => 'public', 'label' => 'Public'],
    ];

    /** Rights the assistant can hold on a datastore, set by the data owner. */
    public const ASSISTANT_RIGHTS = [
        ['id' => 'read', 'label' => 'Read'],
        ['id' => 'quote', 'label' => 'Quote in notes'],
        ['id' => 'write', 'label' => 'Write'],
    ];

    /** What happens to the assistant's rights when the relationship that granted them ends. */
    public const AFTER_RELATIONSHIP = [
        ['id' => 'drop', 'label' => 'Rights end at once'],
        ['id' => 'read_only', 'label' => 'Keep read access to existing items'],
        ['id' => 'sealed', 'label' => 'Notes stay sealed until the owner releases them'],
    ];

    public static function datastores(): array
    {
        return [
            ['id' => 'calendar', 'name' => 'Calendar', 'owner' => 'individual', 'status' => 'built', 'table' => 'personal_events', 'audience' => 'me', 'replication' => 'home_box', 'assistant' => ['read'], 'after' => 'drop', 'note' => 'Events, availability, booking slots.'],
            ['id' => 'tasks', 'name' => 'Tasks and projects', 'owner' => 'individual', 'status' => 'built', 'table' => 'personal_tasks', 'audience' => 'me', 'replication' => 'home_box', 'assistant' => ['read', 'write'], 'after' => 'drop', 'note' => 'Tasks, projects, reminders.'],
            ['id' => 'contacts', 'name' => 'Contacts and CRM', 'owner' => 'individual', 'status' => 'built', 'table' => 'crm_contacts', 'audience' => 'me', 'replication' => 'home_box', 'assistant' => ['read'], 'after' => 'drop', 'note' => 'People, notes, follow-ups, relationship labels.'],
            ['id' => 'inbox', 'name' => 'Mail and drafts', 'owner' => 'individual', 'status' => 'built', 'table' => 'mail_messages', 'audience' => 'me', 'replication' => 'home_box', 'assistant' => ['read'], 'after' => 'drop', 'note' => 'The assistant drafts. The owner approves every send.'],
            ['id' => 'messages', 'name' => 'Private rooms and messages', 'owner' => 'individual', 'status' => 'built', 'table' => null, 'audience' => 'contact', 'replication' => 'home_box', 'assistant' => [], 'after' => 'drop', 'note' => 'Matrix rooms. End-to-end content stays closed to the assistant unless the owner opens a room.'],
            ['id' => 'vault', 'name' => 'Secret vault', 'owner' => 'individual', 'status' => 'built', 'table' => 'user_secrets', 'audience' => 'me', 'replication' => 'home_box', 'assistant' => [], 'after' => 'drop', 'note' => 'Values never reach the assistant. Tools use a scoped key instead.'],
            ['id' => 'profile', 'name' => 'Profile and external links', 'owner' => 'individual', 'status' => 'planned', 'table' => null, 'audience' => 'public', 'replication' => 'community_copy', 'assistant' => ['read', 'write'], 'after' => 'read_only', 'note' => 'Profile card, link records with an audience each.'],
            ['id' => 'assistant_memory', 'name' => 'Assistant memory', 'owner' => 'individual', 'status' => 'planned', 'table' => null, 'audience' => 'me', 'replication' => 'home_box', 'assistant' => ['read', 'write'], 'after' => 'sealed', 'note' => 'Notes the assistant writes for recall. Belongs to the user and travels with them.'],
            ['id' => 'files', 'name' => 'Personal files', 'owner' => 'individual', 'status' => 'planned', 'table' => null, 'audience' => 'me', 'replication' => 'volunteer_copies', 'assistant' => ['read'], 'after' => 'drop', 'note' => 'Documents and media. Encrypted copies on volunteer boxes.'],
            ['id' => 'household_calendar', 'name' => 'Household calendar and chores', 'owner' => 'household', 'status' => 'planned', 'table' => null, 'audience' => 'household', 'replication' => 'volunteer_copies', 'assistant' => ['read', 'write'], 'after' => 'drop', 'note' => 'Shared by the household. Rights follow the household presets.'],
            ['id' => 'household_files', 'name' => 'Household shared files', 'owner' => 'household', 'status' => 'planned', 'table' => null, 'audience' => 'household', 'replication' => 'volunteer_copies', 'assistant' => ['read'], 'after' => 'sealed', 'note' => 'Shared ownership set when the store is made.'],
            ['id' => 'organization_records', 'name' => 'Organization records', 'owner' => 'organization', 'status' => 'partial', 'table' => null, 'audience' => 'organization', 'replication' => 'community_copy', 'assistant' => ['read'], 'after' => 'drop', 'note' => 'Members, boards, ledgers, minutes. Officers set the policy.'],
            ['id' => 'community_record', 'name' => 'Community record', 'owner' => 'community', 'status' => 'built', 'table' => null, 'audience' => 'public', 'replication' => 'community_copy', 'assistant' => ['read', 'quote'], 'after' => 'sealed', 'note' => 'Public square, halls, laws. Closed-session material follows the representatives\' policy.'],
        ];
    }

    public static function replication(): array
    {
        return [
            ['id' => 'home_box', 'label' => 'This box only', 'note' => 'No copies leave the box.'],
            ['id' => 'volunteer_copies', 'label' => 'Encrypted copies on volunteer boxes', 'note' => 'Holders see ciphertext only. Proofs show a copy is intact.'],
            ['id' => 'community_copy', 'label' => 'Community copy', 'note' => 'A community node keeps a copy for the audience that may read it.'],
        ];
    }

    /** The peer circle and consent-ledger choices. */
    public static function sharing(): array
    {
        return [
            'paths' => [
                ['id' => 'lan', 'label' => 'Same network', 'note' => 'Boxes on one LAN talk directly.'],
                ['id' => 'direct', 'label' => 'Direct over the internet', 'note' => 'Signed grant, both boxes reachable.'],
                ['id' => 'relay', 'label' => 'Community relay', 'note' => 'The relay carries ciphertext only.'],
                ['id' => 'tree', 'label' => 'Community tree', 'note' => 'Last resort, up the name chain.'],
            ],
            'resources' => [
                ['id' => 'storage', 'label' => 'Storage', 'unit' => 'GB', 'note' => 'Disk others may use for encrypted copies.'],
                ['id' => 'compute', 'label' => 'Compute', 'unit' => 'hours per week', 'note' => 'Model or job time lent through a subkey.'],
                ['id' => 'bandwidth', 'label' => 'Relay bandwidth', 'unit' => 'GB per month', 'note' => 'Traffic this box may carry for peers.'],
                ['id' => 'hours', 'label' => 'People hours', 'unit' => 'hours per month', 'note' => 'Time offered to the community.'],
            ],
            'link_kinds' => ['Household to household', 'Household to organization', 'Organization to organization', 'Person to person'],
            'grant_scopes' => ['Sync members', 'Share a calendar', 'Share a datastore', 'Direct messages and calls', 'Lend storage', 'Lend compute'],
        ];
    }

    public static function bundles(): array
    {
        return [
            ['id' => 'home', 'name' => 'Home and devices', 'status' => 'running', 'parts' => ['Home Assistant', 'Mosquitto (MQTT)', 'Wyoming voice: openWakeWord, Whisper, Piper'], 'ram_mb' => 1200, 'disk_gb' => 12, 'connects' => 'Tasks and notifications', 'note' => 'Devices, scenes, voice satellites.'],
            ['id' => 'monitoring', 'name' => 'Monitoring', 'status' => 'planned', 'parts' => ['UniFi network controller (running)', 'Zabbix with an agent on every box (planned)', 'Central syslog (planned)'], 'ram_mb' => 1500, 'disk_gb' => 20, 'connects' => 'Tasks for the IT role', 'note' => 'The old Zabbix server has been stopped since June.'],
            ['id' => 'automation', 'name' => 'Automation hub', 'status' => 'running', 'parts' => ['n8n'], 'ram_mb' => 600, 'disk_gb' => 3, 'connects' => 'Scoped keys and webhooks', 'note' => 'Holds outside credentials. Runs the tool catalog and pipelines.'],
            ['id' => 'media', 'name' => 'Media', 'status' => 'running', 'parts' => ['Kodi', 'Jellyfin', 'Deluge'], 'ram_mb' => 1200, 'disk_gb' => 500, 'connects' => 'Files and TV surface', 'note' => 'Shared library for the TV.'],
            ['id' => 'brains', 'name' => 'AI brains', 'status' => 'running', 'parts' => ['Ollama gateway', 'Coding assistant for long jobs', 'Any OpenAI-compatible endpoint'], 'ram_mb' => 4000, 'disk_gb' => 30, 'connects' => 'Assistant keyset', 'note' => 'Local models need the most memory. Cloud endpoints need none.'],
            ['id' => 'voice', 'name' => 'Voice and screens', 'status' => 'running', 'parts' => ['Standalone assistant app', 'Softphone', 'Voice satellites'], 'ram_mb' => 300, 'disk_gb' => 2, 'connects' => 'Assistant surfaces', 'note' => 'TV, speakers, phone.'],
            ['id' => 'office', 'name' => 'Tasks, calendar and mail replacements', 'status' => 'planned', 'parts' => ['Vikunja', 'Radicale', 'Stalwart'], 'ram_mb' => 700, 'disk_gb' => 10, 'connects' => 'Native tasks, calendar, inbox', 'note' => 'Only where the native tools are not enough.'],
            ['id' => 'files', 'name' => 'Files and office', 'status' => 'planned', 'parts' => ['Nextcloud', 'Collabora or OnlyOffice'], 'ram_mb' => 2000, 'disk_gb' => 100, 'connects' => 'Personal and household files', 'note' => 'Large. Fits a home server, not a Pi.'],
            ['id' => 'chat', 'name' => 'Notifications and chat', 'status' => 'planned', 'parts' => ['ntfy', 'Mattermost, Element or Zulip'], 'ram_mb' => 800, 'disk_gb' => 10, 'connects' => 'Rooms and push', 'note' => 'For teams that want more than Matrix rooms.'],
        ];
    }

    public static function externalConnections(): array
    {
        return [
            ['id' => 'google', 'name' => 'Google account', 'kind' => 'Mail, calendar, contacts', 'how' => 'Account sign-in. Token in the vault.', 'status' => 'planned'],
            ['id' => 'microsoft', 'name' => 'Microsoft account', 'kind' => 'Mail, calendar, Teams', 'how' => 'Account sign-in. Token in the vault.', 'status' => 'planned'],
            ['id' => 'twilio', 'name' => 'Twilio', 'kind' => 'Phone numbers and texts', 'how' => 'API key in the vault. Used through n8n.', 'status' => 'planned'],
            ['id' => 'alexa', 'name' => 'Alexa', 'kind' => 'Spoken phrases', 'how' => 'Skill calls the intake webhook. Surface level S2.', 'status' => 'planned'],
            ['id' => 'telegram', 'name' => 'Telegram bot', 'kind' => 'Messages', 'how' => 'Bot token in the vault.', 'status' => 'planned'],
            ['id' => 'whatsapp', 'name' => 'WhatsApp Business', 'kind' => 'Messages', 'how' => 'Business account. Number stays private by default.', 'status' => 'planned'],
            ['id' => 'cloudflare', 'name' => 'Cloudflare', 'kind' => 'Tunnel and DNS', 'how' => 'Tunnel token. Option A in the reach question.', 'status' => 'partial'],
            ['id' => 'cloud_models', 'name' => 'Cloud model provider', 'kind' => 'Assistant compute', 'how' => 'Own key in the vault, or a subkey from a community.', 'status' => 'planned'],
        ];
    }

    /** The Jarvis assistant's tool catalog. Safety class: R read, W reversible write, A needs approval. */
    public static function assistantTools(): array
    {
        return [
            ['id' => 'agenda', 'name' => 'Agenda', 'does' => 'Read today\'s and this week\'s events.', 'class' => 'R', 'min_surface' => 'S2', 'phrases' => ['What is on today', 'What is my week'], 'store' => 'calendar', 'status' => 'partial'],
            ['id' => 'inbox_summary', 'name' => 'Inbox summary', 'does' => 'Count and summarize unread mail and flags.', 'class' => 'R', 'min_surface' => 'S1', 'phrases' => ['Summarize my inbox'], 'store' => 'inbox', 'status' => 'partial'],
            ['id' => 'tasks', 'name' => 'Tasks and reminders', 'does' => 'Capture, list and complete tasks.', 'class' => 'W', 'min_surface' => 'S2', 'phrases' => ['Add a task', 'Remind me'], 'store' => 'tasks', 'status' => 'partial'],
            ['id' => 'contacts', 'name' => 'Contact search', 'does' => 'Find a person and their lines.', 'class' => 'R', 'min_surface' => 'S2', 'phrases' => ['Find a contact'], 'store' => 'contacts', 'status' => 'partial'],
            ['id' => 'schedule', 'name' => 'Scheduling', 'does' => 'Offer slots, book, change, cancel.', 'class' => 'A', 'min_surface' => 'S1', 'phrases' => ['Book a meeting'], 'store' => 'calendar', 'status' => 'planned'],
            ['id' => 'draft_mail', 'name' => 'Draft and send mail', 'does' => 'Draft replies. Sending asks the owner.', 'class' => 'A', 'min_surface' => 'S1', 'phrases' => ['Reply to this'], 'store' => 'inbox', 'status' => 'partial'],
            ['id' => 'texts', 'name' => 'Texts', 'does' => 'Read and dictate replies to texts.', 'class' => 'A', 'min_surface' => 'S1', 'phrases' => ['Read my texts'], 'store' => 'messages', 'status' => 'planned'],
            ['id' => 'phone', 'name' => 'Softphone', 'does' => 'Place and answer calls. Confirms before every dial.', 'class' => 'A', 'min_surface' => 'S1', 'phrases' => ['Call'], 'store' => null, 'status' => 'planned'],
            ['id' => 'play', 'name' => 'Play and transport', 'does' => 'Play media in a room, pause, stop.', 'class' => 'W', 'min_surface' => 'S2', 'phrases' => ['Play in the kitchen', 'Pause'], 'store' => null, 'status' => 'planned'],
            ['id' => 'journal', 'name' => 'Journal and notes', 'does' => 'Write notes to the assistant memory.', 'class' => 'W', 'min_surface' => 'S2', 'phrases' => ['Note that'], 'store' => 'assistant_memory', 'status' => 'planned'],
            ['id' => 'server_status', 'name' => 'Server status', 'does' => 'Read box and service health.', 'class' => 'R', 'min_surface' => 'S2', 'phrases' => ['Server status'], 'store' => null, 'status' => 'planned'],
            ['id' => 'briefing', 'name' => 'Briefing now', 'does' => 'One spoken summary of agenda, inbox and tasks.', 'class' => 'R', 'min_surface' => 'S2', 'phrases' => ['Brief me'], 'store' => null, 'status' => 'planned'],
            ['id' => 'admin', 'name' => 'Provider admin (Twilio, cloud reads)', 'does' => 'Allow-listed actions on outside accounts.', 'class' => 'A', 'min_surface' => 'S1', 'phrases' => [], 'store' => 'vault', 'status' => 'planned'],
        ];
    }

    public static function safetyClasses(): array
    {
        return [
            ['id' => 'R', 'label' => 'Read only', 'note' => 'Runs without asking.'],
            ['id' => 'W', 'label' => 'Reversible write', 'note' => 'Runs, and the owner can undo it.'],
            ['id' => 'A', 'label' => 'Needs approval', 'note' => 'Money, messages sent as the owner, deletes, bookings for others.'],
        ];
    }

    public static function surfaceLevels(): array
    {
        return [
            ['id' => 'S1', 'label' => 'Owner verified', 'note' => 'The app signed in, or a satellite at home.'],
            ['id' => 'S2', 'label' => 'Linked voice assistant', 'note' => 'Alexa or a speaker on the owner\'s devices. Approval-class actions ask first.'],
            ['id' => 'S3', 'label' => 'Phone caller', 'note' => 'Limits follow the caller\'s CRM class.'],
        ];
    }

    public static function tiers(): array
    {
        return [
            ['id' => 0, 'label' => 'Surface rules', 'where' => 'Sentences on the device or speaker', 'model' => false, 'example' => 'Play, stop, pause'],
            ['id' => 1, 'label' => 'Orchestrator', 'where' => 'n8n intake', 'model' => false, 'example' => 'Match a request to a registered tool'],
            ['id' => 2, 'label' => 'Tool-using agent', 'where' => 'Small model with the tool catalog', 'model' => true, 'example' => 'Open questions, multi-step requests'],
            ['id' => 3, 'label' => 'Long mode', 'where' => 'Coding assistant or large model', 'model' => true, 'example' => 'Research and build jobs, result returns as a notification'],
        ];
    }

    public static function brains(): array
    {
        return [
            ['id' => 'self_hosted', 'label' => 'Self-hosted model', 'source' => 'This box or a home server (Ollama)', 'status' => 'running'],
            ['id' => 'personal_cloud', 'label' => 'Personal cloud key', 'source' => 'Own provider account, key in the vault', 'status' => 'planned'],
            ['id' => 'subkey', 'label' => 'Subkey from a household, organization or community', 'source' => 'Relays through the issuer\'s internal keys. Quota per subkey relationship.', 'status' => 'planned'],
            ['id' => 'operator_key', 'label' => 'Operator key at first login', 'source' => 'Lends compute only, never data', 'status' => 'planned'],
        ];
    }

    /** The assistant's surfaces: where a person can speak or type to it. */
    public static function assistantSurfaces(): array
    {
        return [
            ['id' => 'app_chat', 'name' => 'App chat and drawer', 'level' => 'S1', 'status' => 'partial', 'note' => 'Drawer on every page. Browser speech to text and voice capture.'],
            ['id' => 'tv', 'name' => 'TV screen', 'level' => 'S1', 'status' => 'planned', 'note' => 'Standalone assistant app. Pages and answers routed to a screen on request.'],
            ['id' => 'satellite', 'name' => 'Voice satellites', 'level' => 'S1', 'status' => 'planned', 'note' => 'Wake word, speech to text, speech back. Named Jarvis.'],
            ['id' => 'alexa', 'name' => 'Alexa', 'level' => 'S2', 'status' => 'planned', 'note' => 'Phrases reach the same tools through n8n.'],
            ['id' => 'phone', 'name' => 'Phone line', 'level' => 'S3', 'status' => 'planned', 'note' => 'Callers get the tools their CRM class allows.'],
            ['id' => 'telegram', 'name' => 'Messaging bots', 'level' => 'S2', 'status' => 'planned', 'note' => 'Telegram and WhatsApp through n8n.'],
        ];
    }

    /** Jarvis app tabs and what each becomes inside this app. */
    public static function jarvisInventory(): array
    {
        return [
            ['id' => 'phone', 'name' => 'Phone', 'jarvis' => 'Softphone, pending call shared by voice and screen', 'here' => 'Calls through LiveKit and SIP', 'status' => 'planned', 'item' => 'W-0502'],
            ['id' => 'texts', 'name' => 'Texts', 'jarvis' => 'Text inbox with dictated replies', 'here' => 'Messenger and carrier texts', 'status' => 'planned', 'item' => 'W-0509'],
            ['id' => 'contacts', 'name' => 'Contacts', 'jarvis' => 'Search and call', 'here' => 'Contacts and CRM (built)', 'status' => 'partial', 'item' => 'W-0509'],
            ['id' => 'calendar', 'name' => 'Calendar', 'jarvis' => 'Framed dashboard', 'here' => 'Calendar (built)', 'status' => 'partial', 'item' => 'W-0509'],
            ['id' => 'projects', 'name' => 'Projects', 'jarvis' => 'Framed dashboard', 'here' => 'Tasks and projects (built)', 'status' => 'partial', 'item' => 'W-0509'],
            ['id' => 'comms', 'name' => 'Comms', 'jarvis' => 'Unified mail view', 'here' => 'Inbox and approval queue (built)', 'status' => 'partial', 'item' => 'W-0509'],
            ['id' => 'chat', 'name' => 'Chat', 'jarvis' => 'Assistant chat with swappable brains and per-turn usage', 'here' => 'Assistant drawer and assistant page', 'status' => 'partial', 'item' => 'W-0509'],
            ['id' => 'apps', 'name' => 'Apps', 'jarvis' => 'Add an app to the tab list', 'here' => 'Bundles page', 'status' => 'partial', 'item' => 'W-0510'],
            ['id' => 'display', 'name' => 'Display relay', 'jarvis' => 'One window at a time on the TV', 'here' => 'Screen routing from the assistant', 'status' => 'planned', 'item' => 'W-0520'],
            ['id' => 'tabcontrol', 'name' => 'Tab control by voice', 'jarvis' => 'One bridge call per tab, spoken answer returned', 'here' => 'Tool catalog act and speech fields', 'status' => 'planned', 'item' => 'W-0520'],
        ];
    }
}
