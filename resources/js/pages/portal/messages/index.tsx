import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import {
    Archive,
    ArrowLeft,
    BellRing,
    CheckCheck,
    MessageSquareText,
    Plus,
    Send,
} from 'lucide-react';
import { toast } from 'sonner';
import PortalLayout from '@/layouts/portal/portal-layout';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyHeader,
    EmptyTitle,
    EmptyDescription,
} from '@/components/ui/empty';
import { Label } from '@/components/ui/label';
import { postWithToast } from '@/lib/operations';

type ConversationSummary = {
    id: number;
    subject: string;
    lastMessagePreview: string | null;
    lastMessageAt: string | null;
    unreadCount: number;
};

type MessageEntry = {
    id: number;
    senderId: number;
    senderName: string;
    body: string;
    createdAt: string | null;
    isOwn: boolean;
    delivery: { recipients: number; read: number } | null;
};

type ActiveConversation = {
    id: number;
    subject: string;
    participants: Array<{ userId: number; name: string; roleContext: string }>;
    messages: MessageEntry[];
    openRequests: { id: number; type: string; dueAt: string | null }[];
    isArchived: boolean;
};

type MessageableUser = { id: number; name: string };

type Props = {
    conversations: ConversationSummary[];
    activeConversation: ActiveConversation | null;
    messageableUsers: MessageableUser[];
    filters: { archived: boolean; q: string };
    emailNotifications: boolean;
};

function formatTimestamp(value: string | null): string {
    if (!value) return '';

    return new Date(value).toLocaleString('ka-GE', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function MessagesIndex({
    conversations,
    activeConversation,
    messageableUsers,
    filters,
    emailNotifications,
}: Props) {
    const [composeOpen, setComposeOpen] = useState(false);
    const [recipientId, setRecipientId] = useState<number | ''>('');
    const [subject, setSubject] = useState('');
    const [composeBody, setComposeBody] = useState('');
    const [sending, setSending] = useState(false);
    const [replyBody, setReplyBody] = useState('');
    const [replying, setReplying] = useState(false);

    const submitCompose = (event: FormEvent) => {
        event.preventDefault();

        if (recipientId === '') {
            toast.error('აირჩიეთ მიმღები.');

            return;
        }

        setSending(true);
        router.post(
            '/portal/messages',
            {
                recipient_id: recipientId,
                subject,
                body: composeBody,
            },
            {
                onSuccess: () => {
                    setComposeOpen(false);
                    setSubject('');
                    setComposeBody('');
                    setRecipientId('');
                    toast.success('შეტყობინება გაიგზავნა.');
                },
                onError: () =>
                    toast.error('ვერ გაიგზავნა — გადაამოწმეთ ველები.'),
                onFinish: () => setSending(false),
            },
        );
    };

    const submitReply = (event: FormEvent) => {
        event.preventDefault();

        if (!activeConversation || replyBody.trim() === '') return;

        setReplying(true);
        router.post(
            `/portal/messages/${activeConversation.id}/reply`,
            { body: replyBody },
            {
                onSuccess: () => setReplyBody(''),
                onError: () => toast.error('პასუხი ვერ გაიგზავნა.'),
                onFinish: () => setReplying(false),
            },
        );
    };

    return (
        <PortalLayout>
            <Head title="შეტყობინებები" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl">შეტყობინებები</h1>
                <Button onClick={() => setComposeOpen(true)}>
                    <Plus size={18} /> ახალი შეტყობინება
                </Button>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <form
                    role="search"
                    className="flex min-w-[220px] flex-1 gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const q = String(
                            new FormData(event.currentTarget).get('q') ?? '',
                        );
                        router.get('/portal/messages', {
                            q: q || undefined,
                            archived: filters.archived ? 1 : undefined,
                        });
                    }}
                >
                    <label htmlFor="message-search" className="sr-only">
                        ძებნა
                    </label>
                    <input
                        id="message-search"
                        name="q"
                        defaultValue={filters.q}
                        placeholder="ძებნა თემასა და ტექსტში"
                        className="h-11 flex-1 rounded-md border border-slate-300 px-3 text-sm"
                    />
                    <Button type="submit" variant="outline">
                        ძებნა
                    </Button>
                </form>
                <Link
                    href={
                        filters.archived
                            ? '/portal/messages'
                            : '/portal/messages?archived=1'
                    }
                    className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-3 text-sm ring-1 ring-slate-200 hover:bg-slate-50"
                >
                    <Archive size={16} aria-hidden />{' '}
                    {filters.archived ? 'აქტიური საუბრები' : 'არქივი'}
                </Link>
                <label className="flex min-h-11 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="h-4 w-4"
                        checked={emailNotifications}
                        onChange={(event) =>
                            postWithToast(
                                '/portal/messages-preferences',
                                { email: event.target.checked },
                                'პარამეტრი შენახულია.',
                            )
                        }
                    />
                    ახალ შეტყობინებაზე ელფოსტით შეტყობინება
                </label>
            </div>

            <div className="grid gap-0 overflow-hidden rounded-xl border border-slate-200 bg-white md:grid-cols-[360px_1fr]">
                <div
                    className={`border-slate-200 md:border-r ${activeConversation ? 'hidden md:block' : ''}`}
                >
                    {conversations.length === 0 ? (
                        <Empty className="p-8">
                            <EmptyHeader>
                                <MessageSquareText />
                                <EmptyTitle>
                                    ჯერ არცერთი შეტყობინება არ გაქვთ
                                </EmptyTitle>
                                <EmptyDescription>
                                    დაიწყეთ ახალი საუბარი „ახალი შეტყობინების"
                                    ღილაკით.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <ul className="divide-y divide-slate-200">
                            {conversations.map((conversation) => (
                                <li key={conversation.id}>
                                    <Link
                                        href={`/portal/messages/${conversation.id}`}
                                        className={`flex min-h-11 flex-col gap-1 px-4 py-3 hover:bg-slate-50 ${
                                            activeConversation?.id ===
                                            conversation.id
                                                ? 'bg-slate-50'
                                                : ''
                                        }`}
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="truncate font-medium">
                                                {conversation.subject}
                                            </span>
                                            {conversation.unreadCount > 0 && (
                                                <span
                                                    className="flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold text-white"
                                                    style={{
                                                        backgroundColor:
                                                            '#F5683C',
                                                    }}
                                                >
                                                    {conversation.unreadCount}
                                                </span>
                                            )}
                                        </div>
                                        <span className="truncate text-sm text-slate-500">
                                            {conversation.lastMessagePreview}
                                        </span>
                                        <span className="text-xs text-slate-400">
                                            {formatTimestamp(
                                                conversation.lastMessageAt,
                                            )}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className={activeConversation ? '' : 'hidden md:block'}>
                    {!activeConversation ? (
                        <Empty className="p-8">
                            <EmptyHeader>
                                <MessageSquareText />
                                <EmptyTitle>აირჩიეთ საუბარი</EmptyTitle>
                                <EmptyDescription>
                                    მარცხენა სიიდან აირჩიეთ შეტყობინება მისი
                                    სანახავად.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <div className="flex h-full flex-col">
                            <div className="flex items-center gap-3 border-b border-slate-200 p-4">
                                <Link
                                    href="/portal/messages"
                                    className="md:hidden"
                                    aria-label="სიაზე დაბრუნება"
                                >
                                    <ArrowLeft size={20} />
                                </Link>
                                <div className="min-w-0 flex-1">
                                    <h2 className="font-semibold">
                                        {activeConversation.subject}
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        {activeConversation.participants
                                            .map((p) => p.name)
                                            .join(', ')}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        postWithToast(
                                            `/portal/messages/${activeConversation.id}/archive`,
                                            {
                                                archived:
                                                    !activeConversation.isArchived,
                                            },
                                            activeConversation.isArchived
                                                ? 'არქივიდან ამოიღეს.'
                                                : 'დაარქივდა.',
                                        )
                                    }
                                >
                                    <Archive size={16} />{' '}
                                    {activeConversation.isArchived
                                        ? 'აღდგენა'
                                        : 'არქივი'}
                                </Button>
                            </div>

                            {activeConversation.openRequests.map((r) => (
                                <div
                                    key={r.id}
                                    role="status"
                                    className="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-950"
                                >
                                    <span className="flex items-center gap-2">
                                        <BellRing size={16} aria-hidden />
                                        {r.type === 'reply'
                                            ? 'გამგზავნი თქვენს პასუხს ელოდება.'
                                            : 'გამგზავნი ითხოვს დადასტურებას, რომ გაეცანით.'}
                                        {r.dueAt
                                            ? ` ვადა: ${formatTimestamp(r.dueAt)}`
                                            : ''}
                                    </span>
                                    {r.type === 'acknowledge' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                postWithToast(
                                                    `/portal/action-requests/${r.id}/acknowledge`,
                                                    {},
                                                    'დადასტურდა.',
                                                )
                                            }
                                        >
                                            გავეცანი
                                        </Button>
                                    )}
                                </div>
                            ))}

                            <div className="flex-1 space-y-4 overflow-y-auto p-4">
                                {activeConversation.messages.map((message) => (
                                    <div
                                        key={message.id}
                                        className={`max-w-md rounded-xl px-4 py-2 text-sm ${
                                            message.isOwn
                                                ? 'ml-auto bg-[#132B45] text-white'
                                                : 'bg-slate-100 text-slate-800'
                                        }`}
                                    >
                                        {!message.isOwn && (
                                            <p className="mb-1 text-xs font-semibold opacity-70">
                                                {message.senderName}
                                            </p>
                                        )}
                                        <p className="whitespace-pre-wrap">
                                            {message.body}
                                        </p>
                                        <p className="mt-1 text-xs opacity-60">
                                            {formatTimestamp(message.createdAt)}
                                            {message.delivery &&
                                                message.delivery.recipients >
                                                    0 && (
                                                    <span className="ml-2 inline-flex items-center gap-1">
                                                        <CheckCheck
                                                            size={12}
                                                            aria-hidden
                                                        />{' '}
                                                        წაიკითხა{' '}
                                                        {message.delivery.read}/
                                                        {
                                                            message.delivery
                                                                .recipients
                                                        }
                                                    </span>
                                                )}
                                        </p>
                                        {message.isOwn && (
                                            <span className="mt-1 flex gap-2 text-xs">
                                                <button
                                                    type="button"
                                                    className="min-h-8 underline opacity-80"
                                                    onClick={() =>
                                                        postWithToast(
                                                            `/portal/messages/${activeConversation.id}/messages/${message.id}/request-response`,
                                                            { type: 'reply' },
                                                            'პასუხი მოითხოვე.',
                                                        )
                                                    }
                                                >
                                                    პასუხის მოთხოვნა
                                                </button>
                                                <button
                                                    type="button"
                                                    className="min-h-8 underline opacity-80"
                                                    onClick={() =>
                                                        postWithToast(
                                                            `/portal/messages/${activeConversation.id}/messages/${message.id}/request-response`,
                                                            {
                                                                type: 'acknowledge',
                                                            },
                                                            'დადასტურება მოითხოვე.',
                                                        )
                                                    }
                                                >
                                                    დადასტურების მოთხოვნა
                                                </button>
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>

                            <form
                                onSubmit={submitReply}
                                className="flex gap-2 border-t border-slate-200 p-4"
                            >
                                <label className="sr-only" htmlFor="reply-body">
                                    პასუხი
                                </label>
                                <textarea
                                    id="reply-body"
                                    value={replyBody}
                                    onChange={(event) =>
                                        setReplyBody(event.target.value)
                                    }
                                    placeholder="დაწერეთ პასუხი..."
                                    className="h-11 min-h-11 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm"
                                />
                                <Button
                                    type="submit"
                                    disabled={
                                        replying || replyBody.trim() === ''
                                    }
                                    aria-label="პასუხის გაგზავნა"
                                >
                                    <Send size={18} />
                                </Button>
                            </form>
                        </div>
                    )}
                </div>
            </div>

            <Dialog open={composeOpen} onOpenChange={setComposeOpen}>
                <DialogContent>
                    <DialogTitle>ახალი შეტყობინება</DialogTitle>
                    <DialogDescription>
                        აირჩიეთ მიმღები, ვისთანაც სასკოლო კავშირი გაქვთ.
                    </DialogDescription>

                    {messageableUsers.length === 0 ? (
                        <p className="text-sm text-slate-500">
                            ამჟამად არავინაა ხელმისაწვდომი მისაწერად.
                        </p>
                    ) : (
                        <form onSubmit={submitCompose} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="recipient">მიმღები</Label>
                                <select
                                    id="recipient"
                                    value={recipientId}
                                    onChange={(event) =>
                                        setRecipientId(
                                            event.target.value === ''
                                                ? ''
                                                : Number(event.target.value),
                                        )
                                    }
                                    required
                                    className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                                >
                                    <option value="">— აირჩიეთ —</option>
                                    {messageableUsers.map((candidate) => (
                                        <option
                                            key={candidate.id}
                                            value={candidate.id}
                                        >
                                            {candidate.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="subject">თემა</Label>
                                <input
                                    id="subject"
                                    value={subject}
                                    onChange={(event) =>
                                        setSubject(event.target.value)
                                    }
                                    required
                                    maxLength={255}
                                    className="h-11 w-full rounded-md border border-slate-300 px-3 text-sm"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="compose-body">
                                    შეტყობინება
                                </Label>
                                <textarea
                                    id="compose-body"
                                    value={composeBody}
                                    onChange={(event) =>
                                        setComposeBody(event.target.value)
                                    }
                                    required
                                    className="min-h-28 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                                />
                            </div>
                            <Button
                                type="submit"
                                disabled={sending}
                                className="w-full"
                            >
                                გაგზავნა
                            </Button>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </PortalLayout>
    );
}
