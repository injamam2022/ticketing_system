<?php

namespace App\Controllers;

use App\Libraries\WorkspaceStore;
use InvalidArgumentException;

class Institutes extends BaseController
{
    use SessionProtection;

    public function index()
    {
        if (!session()->has('user_id')) { return redirect()->to(site_url('login')); }
        $this->response->setHeader('Cache-Control', 'no-store');
        $data = (new WorkspaceStore())->data();
        return view('institutes', [
            'pageTitle' => 'Masters',
            'staff' => true,
            'institutes' => $data['institutes'],
            'projects' => $data['projects'],
            'csrf' => $this->token(),
            'error' => session()->getFlashdata('error'),
            'success' => session()->getFlashdata('success'),
            'old' => session()->getFlashdata('old') ?? [],
            'editing' => session()->getFlashdata('editing'),
        ]);
    }

    public function save(?int $id = null)
    {
        return $this->mutate(
            function ($store) use ($id) { $store->saveInstitute($this->request->getPost(), $id); },
            $id ?? 'new',
            $id ? 'Institute updated.' : 'Institute added.',
            ['name']
        );
    }

    public function saveWork(int $id)
    {
        return $this->mutate(function ($store) use ($id) {
            $post = $this->request->getPost();
            $post['institute_id'] = $id;
            $store->saveProject($post);
        }, 'work-' . $id, 'Master\'s project work added.', ['name', 'description', 'color']);
    }

    public function linkWork(int $id)
    {
        return $this->mutate(
            fn ($store) => $store->assignProjectInstitute($this->request->getPost('project_id'), $id),
            'link-' . $id,
            'Project work linked to this institute.',
            ['project_id']
        );
    }

    private function mutate(callable $action, int|string $editing, string $success, array $fields)
    {
        if (!session()->has('user_id')) { return redirect()->to(site_url('login')); }
        if (!$this->csrfValid()) { return $this->response->setStatusCode(403)->setBody('Your session expired. Reload the page and try again.'); }
        try {
            $action(new WorkspaceStore());
            return redirect()->to(site_url('institutes'))->with('success', $success);
        } catch (InvalidArgumentException $error) {
            $old = array_filter(array_intersect_key($this->request->getPost() ?? [], array_flip($fields)), 'is_string');
            return redirect()->to(site_url('institutes'))->with('error', $error->getMessage())->with('old', $old)->with('editing', $editing);
        }
    }
}
