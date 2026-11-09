<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response, error pages included (NFR-SEC-08).
 *
 * Registered as global middleware so it also covers responses that never
 * reach a route (404s, maintenance mode, /up). A fresh nonce per request is
 * given to Laravel's Vite helper, which adds it to every tag it renders
 * (@vite, @fonts); app.blade.php exposes it to Vite and Inertia for the
 * styles they inject at runtime.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->add([
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Legacy twin of frame-ancestors 'none' for older browsers.
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ]);

        // HTTPS only, and never locally: a pinned HSTS entry for a *.test
        // host would break plain-HTTP development.
        if ($request->isSecure() && ! app()->isLocal()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];
        $assets = ["'self'"];

        // `npm run dev`: scripts, styles and HMR come from the Vite server.
        // Vite injects <style> tags while developing, so inline styles are
        // allowed there (and only there): browsers ignore 'unsafe-inline'
        // once a nonce is listed, so the nonce is dropped from style-src.
        if (($devServer = $this->viteDevServer()) !== null) {
            $script[] = $devServer;
            $style = ["'self'", "'unsafe-inline'", $devServer];
            $connect[] = $devServer;
            $connect[] = preg_replace('/^http/', 'ws', $devServer);
            $assets[] = $devServer;
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'img-src '.implode(' ', [...$assets, 'data:']),
            'font-src '.implode(' ', $assets),
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /** Origin of the running Vite dev server, from public/hot, or null. */
    private function viteDevServer(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $url = trim((string) file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
