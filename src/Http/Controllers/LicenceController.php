<?php

namespace Passorde\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Passorde\Connectors\ConnectorRegistry;
use Passorde\Licence\Contracts\LicenceStore;
use Passorde\Licence\Exceptions\LicenceInvalidException;
use Passorde\Licence\Exceptions\LicenceStoreException;
use Passorde\Licence\LicenceKey;
use Passorde\Licence\LicenceManager;
use Passorde\Licence\LicenceRefresher;
use Passorde\Licence\LicenceText;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LicenceController extends Controller
{
    /** A key is under a kilobyte; anything larger than this is not one. */
    private const MAX_BYTES = LicenceKey::MAX_LENGTH;

    /** Where vendor:publish --tag=passorde-assets puts the stylesheet, under public/. */
    public const PUBLISHED_STYLESHEET = 'vendor/passorde/licence.css';

    public static function stylesheetPath(): string
    {
        return __DIR__.'/../../../resources/css/licence.css';
    }

    public function show(LicenceManager $manager, LicenceStore $store, ConnectorRegistry $connectors): View
    {
        $status = $manager->status();

        try {
            $lastRefresh = $store->lastRefreshAt()?->format('Y-m-d H:i');
        } catch (LicenceStoreException) {
            $lastRefresh = null;
        }

        return view('passorde::licence', [
            'stylesheet' => $this->stylesheetUrl(),
            'backUrl' => $this->backUrl(),
            'status' => $status,
            'licence' => $status->licence,
            'lastRefresh' => $lastRefresh,
            'connectorNames' => $status->licence === null ? null : implode(', ', array_map($connectors->name(...), $status->licence->connectors)),
            'notices' => $connectors->notices(),
            't' => LicenceText::get(...),
        ]);
    }

    /**
     * The page has no inline style, so that it works under a style-src 'self' policy.
     */
    public function stylesheet(): BinaryFileResponse
    {
        return response()->file(self::stylesheetPath(), [
            'Content-Type' => 'text/css; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function install(Request $request, LicenceManager $manager): RedirectResponse
    {
        $file = $request->file('licence_file');
        $raw = $file instanceof UploadedFile && $file->isValid()
            ? $file->get()
            : trim($request->string('licence')->toString());

        if ($raw === false || $raw === '') {
            return $this->backWith('passorde_error', LicenceText::get('install.empty'));
        }

        if (strlen($raw) > self::MAX_BYTES) {
            return $this->backWith('passorde_error', LicenceText::get('install.too_large'));
        }

        try {
            $manager->install($raw);
        } catch (LicenceInvalidException $exception) {
            return $this->backWith('passorde_error', LicenceText::get('install.refused', ['reason' => $exception->getMessage()]));
        } catch (LicenceStoreException $exception) {
            report($exception);

            return $this->backWith('passorde_error', LicenceText::get('install.store_failed', ['reason' => $exception->getMessage()]));
        }

        return $this->backWith('passorde_ok', LicenceText::get('install.success'));
    }

    public function refresh(LicenceRefresher $refresher): RedirectResponse
    {
        $result = $refresher->refresh();

        return $this->backWith($result->failed() ? 'passorde_error' : 'passorde_ok', $result->message);
    }

    /**
     * The published copy when the host published it, the package route otherwise; versioned by content either way.
     */
    private function stylesheetUrl(): string
    {
        $published = public_path(self::PUBLISHED_STYLESHEET);

        if (is_file($published)) {
            return asset(self::PUBLISHED_STYLESHEET).'?v='.substr((string) md5_file($published), 0, 12);
        }

        return route('passorde.licence.css', ['v' => substr((string) md5_file(self::stylesheetPath()), 0, 12)]);
    }

    /**
     * passorde-connect.route.back_url, or the root of the application when it is empty.
     */
    private function backUrl(): string
    {
        $url = config('passorde-connect.route.back_url');

        return is_string($url) && $url !== '' ? $url : '/';
    }

    private function backWith(string $key, string $message): RedirectResponse
    {
        return redirect()->route('passorde.licence.show')->with($key, $message);
    }
}
