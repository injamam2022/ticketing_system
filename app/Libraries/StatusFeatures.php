<?php

namespace App\Libraries;

use InvalidArgumentException;

trait StatusFeatures
{
    public const STATUS_CATEGORIES = ['backlog' => 'Backlog', 'todo' => 'To do', 'active' => 'In progress', 'review' => 'In review', 'done' => 'Completed', 'canceled' => 'Canceled'];

    public function statuses(): array
    {
        return $this->db->query('SELECT * FROM workspace_statuses ORDER BY position,id')->fetchAll();
    }

    private function defaultStatus(): string
    {
        return (string) $this->run('SELECT name FROM workspace_statuses WHERE system_key=?', ['Todo'])->fetchColumn();
    }

    public function saveStatus(array $input, ?int $id = null): int
    {
        $old = $id ? $this->run('SELECT * FROM workspace_statuses WHERE id=?', [$id])->fetch() : null;
        if ($id && !$old) { throw new InvalidArgumentException('Status not found.'); }
        $name = $input['name'] ?? null;
        $color = $input['color'] ?? null;
        $category = $input['category'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 30 || preg_match('/[\x00-\x1f\x7f]/', $name)) { throw new InvalidArgumentException('Use a status name between 1 and 30 characters.'); }
        $name = trim($name);
        if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { throw new InvalidArgumentException('Choose a valid status color.'); }
        if (!is_string($category) || !isset(self::STATUS_CATEGORIES[$category])) { throw new InvalidArgumentException('Choose a valid status category.'); }
        $this->db->beginTransaction();
        try {
            $duplicate = $this->run('SELECT id FROM workspace_statuses WHERE LOWER(name)=LOWER(?) AND id<>?', [$name, $id ?? 0])->fetch();
            if ($duplicate) { throw new InvalidArgumentException('A status already uses that name.'); }
            if ($id) {
                $this->run('UPDATE workspace_statuses SET name=?,color=?,category=? WHERE id=?', [$name, strtolower($color), $category, $id]);
                if ($name !== $old['name']) { $this->run('UPDATE issues SET status=?,updated_at=? WHERE status=?', [$name, gmdate('c'), $old['name']]); }
            } else {
                $position = (int) $this->db->query('SELECT COALESCE(MAX(position),-1)+1 FROM workspace_statuses')->fetchColumn();
                $this->run('INSERT INTO workspace_statuses (name,color,category,position) VALUES (?,?,?,?)', [$name, strtolower($color), $category, $position]);
                $id = (int) $this->db->lastInsertId();
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            if ($error instanceof \PDOException && $error->getCode() === '23000') { throw new InvalidArgumentException('A status already uses that name.'); }
            throw $error;
        }
        return $id;
    }

    public function moveStatus(int $id, mixed $direction): void
    {
        if (!in_array($direction, ['up', 'down'], true)) { throw new InvalidArgumentException('Choose a valid direction.'); }
        $this->db->beginTransaction();
        try {
            $statuses = $this->statuses();
            $index = array_search($id, array_map('intval', array_column($statuses, 'id')), true);
            if ($index === false) { throw new InvalidArgumentException('Status not found.'); }
            $next = $index + ($direction === 'up' ? -1 : 1);
            if (isset($statuses[$next])) { [$statuses[$index], $statuses[$next]] = [$statuses[$next], $statuses[$index]]; }
            foreach ($statuses as $position => $status) { $this->run('UPDATE workspace_statuses SET position=? WHERE id=?', [$position, $status['id']]); }
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }
}
