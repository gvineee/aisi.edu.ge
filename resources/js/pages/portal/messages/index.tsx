import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowLeft, MessageSquareText, Plus, Send } from 'lucide-react';
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
};

type ActiveConversation = {
    id: number;
    subject: string;
    participants: Array<{ userId: number; name: string; roleContext: string }>;
    messages: MessageEntry[];
};

type MessageableUser = { id: number; name: string };

type Props = {
    conversations: ConversationSummary[];
    activeConversation: ActiveConversation | null;
    messageableUsers: MessageableUser[];
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

            <div className="mb-6 flex items-center justify-between">
                <h1 className="text-2xl">შეტყობინებები</h1>
                <Button onClick={() => setComposeOpen(true)}>
                    <Plus size={18} /> ახალი შეტყობინება
                </Button>
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
                                <div>
                                    <h2 className="font-semibold">
                                        {activeConversation.subject}
                                    </h2>
                                    <p className="text-xs text-slate-500">
                                        {activeConversation.participants
                                            .map((p) => p.name)
                                            .join(', ')}
                                    </p>
                                </div>
                            </div>

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
                                        </p>
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
