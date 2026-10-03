<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Communications\Actions\CreateClassBroadcast;
use App\Domain\Communications\Actions\MarkConversationRead;
use App\Domain\Communications\Actions\ResolveMessageableUsers;
use App\Domain\Communications\Actions\SendMessage;
use App\Domain\Communications\Actions\StartConversation;
use App\Domain\Communications\Models\ActionRequest;
use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Communications\Models\Message;
use App\Domain\Communications\Models\MessageBroadcast;
use App\Domain\Communications\Models\MessageDelivery;
use App\Domain\Communications\Models\NotificationPreference;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\PortalContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ReplyToConversationRequest;
use App\Http\Requests\Portal\StoreConversationRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "შეტყობინებები" — every role's conversation inbox (docs/09-design-source-map.md
 * maps prototype `Inbox` here). Who a user may message at all is decided
 * entirely by `ResolveMessageableUsers` (real class/family relationships),
 * never by an open "any tenant user" picker.
 */
class MessageController extends Controller
{
    public function index(Request $request, CurrentTenant $currentTenant): Response
    {
        return $this->render($request, $currentTenant, null);
    }

    public function show(Request $request, CurrentTenant $currentTenant, Conversation $conversation, MarkConversationRead $markConversationRead): Response
    {
        $tenant = $currentTenant->get();
        abort_unless($conversation->tenant_id === $tenant->id, 404);

        $participant = ConversationParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->first();

        abort_unless($participant !== null, 403);

        $markConversationRead->handle($tenant->id, $conversation, $request->user());

        return $this->render($request, $currentTenant, $conversation);
    }

    public function store(StoreConversationRequest $request, CurrentTenant $currentTenant, StartConversation $startConversation, PortalContext $portalContext): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $user = $request->user();
        $activeRole = $portalContext->resolveActiveRole($request, $tenant->id, $user->id);

        abort_if($activeRole === null, 403);

        $recipientId = $request->integer('recipient_id');
        $messageable = app(ResolveMessageableUsers::class)->forUser($tenant->id, $user->id);
        $recipient = $messageable->firstWhere('id', $recipientId);

        abort_unless($recipient instanceof User, 403, 'ეს მომხმარებელი თქვენთვის ხელმისაწვდომი მიმღები არ არის.');

        $recipientRole = $portalContext->activeRoles($tenant->id, $recipient->id)[0] ?? 'user';

        $conversation = $startConversation->handle(
            tenantId: $tenant->id,
            initiator: $user,
            initiatorRoleContext: $activeRole,
            recipient: $recipient,
            recipientRoleContext: $recipientRole,
            subject: $request->string('subject')->toString(),
            body: $request->string('body')->toString(),
        );

        return redirect()->route('messages.show', $conversation);
    }

    public function reply(ReplyToConversationRequest $request, CurrentTenant $currentTenant, Conversation $conversation, SendMessage $sendMessage): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($conversation->tenant_id === $tenant->id, 404);

        $isParticipant = ConversationParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        abort_unless($isParticipant, 403);

        $sendMessage->handle($tenant->id, $conversation, $request->user(), $request->string('body')->toString());

        return redirect()->route('messages.show', $conversation);
    }

    public function broadcast(Request $request, CurrentTenant $currentTenant, CreateClassBroadcast $action): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $data = $request->validate([
            'school_class_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $class = SchoolClass::query()->where('tenant_id', $tenant->id)->whereKey((int) $data['school_class_id'])->first();
        abort_if($class === null, 404);

        $broadcast = $action->handle($tenant->id, $request->user(), $class, $data['subject'], $data['body']);

        return back()->with('toast', ['type' => 'success', 'message' => "განცხადება იგზავნება {$broadcast->recipients_count} ოჯახთან."]);
    }

    public function archive(Request $request, CurrentTenant $currentTenant, Conversation $conversation): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($conversation->tenant_id === $tenant->id, 404);

        $participant = ConversationParticipant::query()->where('tenant_id', $tenant->id)->where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)->first();
        abort_unless($participant !== null, 403);

        // Personal archive: hides the thread for this participant only.
        $participant->archived_at = $request->boolean('archived', true) ? Carbon::now() : null;
        $participant->save();

        return back();
    }

    /**
     * The sender of a message asks the other participants to reply or
     * acknowledge it; it shows in their day centre until done.
     */
    public function requestResponse(Request $request, CurrentTenant $currentTenant, Conversation $conversation, Message $message): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($conversation->tenant_id === $tenant->id && $message->tenant_id === $tenant->id && $message->conversation_id === $conversation->id, 404);
        abort_unless($message->sender_id === $request->user()->id, 403);

        $data = $request->validate(['type' => ['required', 'in:reply,acknowledge'], 'due_at' => ['nullable', 'date', 'after:now']]);

        foreach (ConversationParticipant::query()->where('tenant_id', $tenant->id)->where('conversation_id', $conversation->id)->where('user_id', '!=', $request->user()->id)->pluck('user_id') as $targetId) {
            $actionRequest = ActionRequest::query()->firstOrNew([
                'conversation_id' => $conversation->id, 'message_id' => $message->id, 'target_user_id' => $targetId, 'type' => $data['type'], 'status' => 'open',
            ]);
            $actionRequest->tenant_id = $tenant->id;
            $actionRequest->requested_by = $request->user()->id;
            $actionRequest->due_at = isset($data['due_at']) ? Carbon::parse($data['due_at'], $tenant->timezone)->utc() : null;
            $actionRequest->save();
        }

        return back();
    }

    public function acknowledge(Request $request, CurrentTenant $currentTenant, ActionRequest $actionRequest): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($actionRequest->tenant_id === $tenant->id, 404);
        abort_unless($actionRequest->target_user_id === $request->user()->id, 403);

        $actionRequest->status = 'done';
        $actionRequest->completed_at = Carbon::now();
        $actionRequest->save();

        return back();
    }

    public function preferences(Request $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();
        $request->validate(['email' => ['required', 'boolean']]);

        $preference = NotificationPreference::query()->firstOrNew(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'category' => 'messages', 'channel' => 'email']);
        $preference->tenant_id = $tenant->id;
        $preference->enabled = $request->boolean('email');
        $preference->save();

        return back();
    }

    private function render(Request $request, CurrentTenant $currentTenant, ?Conversation $activeConversation): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $showArchived = $request->boolean('archived');
        $search = mb_strtolower(trim((string) $request->string('q')));

        $participantRows = ConversationParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->when(! $showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->when($showArchived, fn ($query) => $query->whereNotNull('archived_at'))
            ->with(['conversation' => fn ($query) => $query->with('messages')])
            ->get()
            ->filter(fn (ConversationParticipant $participant) => $participant->conversation !== null)
            // Search the subject and message bodies of the viewer's own conversations only.
            ->filter(fn (ConversationParticipant $participant) => $search === ''
                || str_contains(mb_strtolower($participant->conversation->subject), $search)
                || $participant->conversation->messages->contains(fn (Message $m) => str_contains(mb_strtolower($m->body), $search)))
            ->sortByDesc(fn (ConversationParticipant $participant) => $this->lastActivityAt($participant->conversation));

        $conversations = $participantRows->map(fn (ConversationParticipant $participant) => $this->formatConversationSummary($tenant->id, $user, $participant))->values();

        $messageableUsers = app(ResolveMessageableUsers::class)->forUser($tenant->id, $user->id);

        $activeConversationData = null;

        if ($activeConversation !== null) {
            $activeConversation->load(['messages.sender', 'participants.user']);

            $activeConversationData = [
                'id' => $activeConversation->id,
                'subject' => $activeConversation->subject,
                'participants' => $activeConversation->participants->map(fn (ConversationParticipant $participant) => [
                    'userId' => $participant->user_id,
                    'name' => $participant->user->name,
                    'roleContext' => $participant->role_context,
                ])->values(),
                'messages' => $activeConversation->messages->map(function (Message $message) use ($user, $tenant) {
                    $isOwn = $message->sender_id === $user->id;

                    return [
                        'id' => $message->id,
                        'senderId' => $message->sender_id,
                        'senderName' => $message->sender->name,
                        'body' => $message->body,
                        'createdAt' => $message->created_at?->toIso8601String(),
                        'isOwn' => $isOwn,
                        // Read receipts only for the sender of the message.
                        'delivery' => $isOwn ? [
                            'recipients' => MessageDelivery::query()->where('tenant_id', $tenant->id)->where('message_id', $message->id)->where('channel', MessageDelivery::CHANNEL_IN_APP)->count(),
                            'read' => MessageDelivery::query()->where('tenant_id', $tenant->id)->where('message_id', $message->id)->where('channel', MessageDelivery::CHANNEL_IN_APP)->whereNotNull('read_at')->count(),
                        ] : null,
                    ];
                })->values(),
                'openRequests' => ActionRequest::query()->where('tenant_id', $tenant->id)->where('conversation_id', $activeConversation->id)
                    ->where('target_user_id', $user->id)->where('status', 'open')->get()
                    ->map(fn (ActionRequest $r) => ['id' => $r->id, 'type' => $r->type, 'dueAt' => $r->due_at?->toIso8601String()])->values(),
                'isArchived' => ConversationParticipant::query()->where('tenant_id', $tenant->id)->where('conversation_id', $activeConversation->id)
                    ->where('user_id', $user->id)->whereNotNull('archived_at')->exists(),
            ];
        }

        return Inertia::render('portal/messages/index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversationData,
            'messageableUsers' => $messageableUsers->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name,
            ])->values(),
            'filters' => ['archived' => $showArchived, 'q' => (string) $request->string('q')],
            'emailNotifications' => NotificationPreference::emailEnabled($tenant->id, $user->id),
            'broadcastClasses' => app(CreateClassBroadcast::class)->classesFor($tenant->id, $user)
                ->map(fn (SchoolClass $class) => ['id' => $class->id, 'name' => $class->name])->values(),
            'recentBroadcasts' => MessageBroadcast::query()->where('tenant_id', $tenant->id)->where('sender_id', $user->id)->with('schoolClass')
                ->latest('id')->limit(5)->get()->map(fn (MessageBroadcast $b) => [
                    'id' => $b->id,
                    'subject' => $b->subject,
                    'className' => $b->schoolClass->name ?? null,
                    'status' => $b->status,
                    'recipients' => $b->recipients_count,
                    'delivered' => $b->delivered_count,
                    'createdAt' => $b->created_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    private function lastActivityAt(Conversation $conversation): CarbonImmutable
    {
        $lastMessage = $conversation->messages->last();

        if ($lastMessage !== null && $lastMessage->created_at !== null) {
            return $lastMessage->created_at;
        }

        return $conversation->created_at ?? CarbonImmutable::now();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatConversationSummary(int $tenantId, User $viewer, ConversationParticipant $participant): array
    {
        $conversation = $participant->conversation;
        $lastMessage = $conversation->messages->last();

        $unreadCount = $participant->last_read_message_id === null
            ? $conversation->messages->count()
            : $conversation->messages->where('id', '>', $participant->last_read_message_id)->count();

        return [
            'id' => $conversation->id,
            'subject' => $conversation->subject,
            'lastMessagePreview' => $lastMessage?->body,
            'lastMessageAt' => $lastMessage?->created_at?->toIso8601String(),
            'unreadCount' => $unreadCount,
        ];
    }
}
