<?php

namespace App\Models\Concerns;

use App\Models\ModelAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            static::writeAudit($model, 'created', null, static::auditSnapshot($model));
        });

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }

            $old = [];
            foreach (array_keys($changes) as $key) {
                $old[$key] = $model->getOriginal($key);
            }

            static::writeAudit($model, 'updated', $old, $changes);
        });

        static::deleted(function (Model $model) {
            static::writeAudit($model, 'deleted', static::auditSnapshot($model), null);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                static::writeAudit($model, 'restored', null, static::auditSnapshot($model));
            });
        }
    }

    public function audits()
    {
        return $this->morphMany(ModelAudit::class, 'auditable')->latest('id');
    }

    protected static function auditSnapshot(Model $model): array
    {
        $attrs = $model->getAttributes();
        unset($attrs['password'], $attrs['remember_token']);

        return $attrs;
    }

    protected static function writeAudit(Model $model, string $action, ?array $old, ?array $new): void
    {
        ModelAudit::create([
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'user_id' => Auth::id(),
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
