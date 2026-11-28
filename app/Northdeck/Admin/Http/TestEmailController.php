<?php

namespace App\Northdeck\Admin\Http;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Support\Audit\Audited;
use App\Support\Install\MailSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/** Sends one email with the settings in force now, so admins see they work. */
final class TestEmailController extends Controller
{
    #[Audited]
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:254']]);
        $to = (string) $data['to'];
        $mailer = (string) config('mail.default');
        $panel = (string) config('app.name');

        Mail::purge($mailer);
        try {
            Mail::mailer($mailer)->raw(
                "This is a test from {$panel}. Email works, so invitations, alerts and password resets will arrive.\n\n".config('app.url'),
                fn ($message) => $message->to($to)->subject("{$panel} can send email"),
            );
        } catch (Throwable $e) {
            AdminAction::record($request->user(), 'northdeck.test_email', meta: ['to' => $to, 'mailer' => $mailer, 'sent' => false]);

            throw ValidationException::withMessages(['to' => $mailer === 'smtp'
                ? MailSetup::plainError($e, [
                    'host' => (string) config('mail.mailers.smtp.host'),
                    'port' => (int) config('mail.mailers.smtp.port'),
                    'password' => (string) config('mail.mailers.smtp.password'),
                    'from_address' => (string) config('mail.from.address'),
                ])
                : __('It didn’t send: :error', ['error' => strtok($e->getMessage(), "\n") ?: $e->getMessage()])]);
        }

        AdminAction::record($request->user(), 'northdeck.test_email', meta: ['to' => $to, 'mailer' => $mailer, 'sent' => true]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $mailer === 'log'
            ? __('Written to the log, not sent: “Send with” is set to Log.')
            : __('Sent to :to. Check the inbox (and spam).', ['to' => $to])]);

        return back();
    }
}
