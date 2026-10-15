<?php

namespace App\Controllers;

trait SessionProtection
{
    private function token(): string
    {
        if (!session()->has('workspace_csrf')) { session()->set('workspace_csrf', bin2hex(random_bytes(32))); }
        return session()->get('workspace_csrf');
    }

    private function csrfValid(): bool
    {
        $provided = $this->request->getHeaderLine('X-CSRF-Token') ?: $this->request->getPost('csrf');
        return is_string($provided) && hash_equals($this->token(), $provided);
    }
}
