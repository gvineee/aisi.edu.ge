<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Communications\Actions\MarkConversationRead;
use App\Domain\Communications\Actions\ResolveMessageableUsers;
use App\Domain\Communications\Actions\SendMessage;
use App\Domain\Communications\Actions\StartConversation;
use App\Domain\Communications\Models\Conversation;
use App\Domain\Communications\Models\ConversationParticipant;
use App\Domain\Communications\Models\Message;
use App\Domain\Tenancy\CurrentTenant;
use App\Domain\Tenancy\PortalContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ReplyToConversationRequest;
use App\Http\Requests\Portal\StoreConversationRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    private function render(Request $request, CurrentTenant $currentTenant, ?Conversation $activeConversation): Response
    {
        $tenant = $currentTenant->get();
        $user = $request->user();

        $participantRows = ConversationParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->with(['conversation' => fn ($query) => $query->with('messages')])
            ->get()
            ->filter(fn (ConversationParticipant $participant) => $participant->conversation !== null)
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
                'messages' => $activeConversation->messages->map(fn (Message $message) => [
                    'id' => $message->id,
                    'senderId' => $message->sender_id,
                    'senderName' => $message->sender->name,
                    'body' => $message->body,
                    'createdAt' => $message->created_at?->toIso8601String(),
                    'isOwn' => $message->sender_id === $user->id,
                ])->values(),
            ];
        }

        return Inertia::render('portal/messages/index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversationData,
            'messageableUsers' => $messageableUsers->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name,
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
