<?php

namespace Modules\MetaWhatsApp\Http\Middleware;

use Closure;
use Modules\MetaWhatsApp\Models\WhatsAppAccount;

/**
 * Issue #44. FreeScout's sidebar offers a "New Conversation" button on every
 * mailbox, including one wired to WhatsApp, and the form behind it cannot
 * work here: the Cloud API lets a business reply inside a 24-hour window the
 * customer opened, or send an approved template, and nothing else. An agent
 * who used it got a conversation that looked saved while nothing was ever
 * delivered and nothing was written to any log.
 *
 * Hiding the button (see the sidebar CSS in the service provider) removes the
 * path everyone takes, but a hidden button is not a closed route and the
 * damage the reporter described (several conversations for the same customer
 * in one mailbox) comes from the route. So the refusal lives here, in front
 * of every route that can write one.
 *
 * What this does NOT guard is the save that follows the form, which travels
 * through POST /conversation/ajax: one endpoint multiplexed by an `action`
 * parameter for every conversation operation in the application, so guarding
 * it would mean guarding a surface shared with the whole of FreeScout.
 * Refusing the form closes that path in practice, because the save needs the
 * CSRF token and page state only that form carries. The clone route needs no
 * such reasoning: it writes the conversation itself, so refusing it is the
 * whole guard.
 */
class RefuseNewConversation
{
    /**
     * Both core routes that put a second conversation in a mailbox.
     *
     * `conversations.create` renders the form. `conversations.clone_conversation`
     * does not: it builds the conversation and its thread inside the GET and
     * redirects to the result (ConversationsController::cloneConversation),
     * copying the original's type. On a WhatsApp mailbox that writes a second
     * CHAT conversation for the same customer, so it is both the duplicate
     * issue #44 reports and a row that ProcessInboundWebhook's reuse query
     * cannot tell from the real one: restricting that query to chat
     * conversations does not help when the duplicate is a chat conversation.
     *
     * Found by review after the form route was already closed, which is why
     * it is a list and not a comparison. A third route rendering the same
     * thing belongs here too.
     */
    const REFUSED_ROUTES = [
        'conversations.create',
        'conversations.clone_conversation',
    ];

    public function handle($request, Closure $next)
    {
        $route = $request->route();
        if (!$route || !in_array($route->getName(), self::REFUSED_ROUTES, true)) {
            return $next($request);
        }

        $mailboxId = $route->parameter('mailbox_id');
        if (!$mailboxId || !WhatsAppAccount::where('mailbox_id', $mailboxId)->exists()) {
            return $next($request);
        }

        // The mailbox the agent came from, so refusing does not strand them
        // on an error page. The flash says why, because a redirect with no
        // explanation is the silent failure this issue is about.
        \Session::flash(
            'flash_error_floating',
            __('metawhatsapp::metawhatsapp.new_conversation_refused')
        );

        return redirect()->route('mailboxes.view', ['id' => $mailboxId]);
    }
}
