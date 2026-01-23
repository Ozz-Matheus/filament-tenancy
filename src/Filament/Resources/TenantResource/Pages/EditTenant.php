<?php

namespace TomatoPHP\FilamentTenancy\Filament\Resources\TenantResource\Pages;

use TomatoPHP\FilamentTenancy\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open')
                ->label(trans('filament-tenancy::messages.actions.view'))
                ->icon('heroicon-s-link')
                ->url(fn($record) => request()->getScheme() . "://" . $record->domains()->first()?->domain . '.' . config('filament-tenancy.central_domain') . '/' . filament('filament-tenancy')->panel)
                ->openUrlInNewTab(),
            Actions\DeleteAction::make()
                ->icon('heroicon-s-trash')
                ->label(trans('filament-tenancy::messages.actions.delete'))
                ->before(function ($record) {
                    // Force close all connections to the tenant database
                    $dbName = config('tenancy.database.prefix') . $record->id . config('tenancy.database.suffix');
                    
                    try {
                        // Close all connections
                        DB::purge('dynamic');
                        DB::purge('pgsql');
                        
                        // Force terminate all connections to the database with retry
                        for ($i = 0; $i < 5; $i++) {
                            DB::connection('pgsql')->statement("SELECT pg_terminate_backend(pid, true) FROM pg_stat_activity WHERE datname = '{$dbName}' AND pid <> pg_backend_pid()");
                            sleep(2); // Wait 2 seconds between attempts
                        }
                        
                        // Additional wait to ensure connections are closed
                        sleep(2);
                        
                        // Check if database exists before triggering deletion
                        config(['database.connections.dynamic.database' => $dbName]);
                        DB::connection('dynamic')->getPdo();
                        
                        // Database exists, trigger deletion event
                        event(new \Stancl\Tenancy\Events\TenantDeleted($record));
                    } catch (\Exception $e) {
                        // Database doesn't exist or connection failed, skip deletion event
                        Log::info("Database {$dbName} does not exist or connection failed, skipping deletion event: " . $e->getMessage());
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'],
        ];

        if (isset($data['password'])) {
            $updateData["password"] = $data['password'];
        }

        try {
            if (!config('filament-tenancy.single_database')) {
                $dbName = config('tenancy.database.prefix') . $record->id . config('tenancy.database.suffix');
                config(['database.connections.dynamic.database' => $dbName]);
            }
            DB::purge('dynamic');

            DB::connection('dynamic')->getPdo();
        } catch (\Exception $e) {
            throw new \Exception("Failed to connect to tenant database: {$dbName}");
        }

        $user = DB::connection('dynamic')
            ->table('users')
            ->where('email', $record->email);

        if (config('filament-tenancy.single_database')) {
            $user = $user->where('tenant_id', $record->id);

            $updateData['tenant_id'] = $record->id;
        }

        $user->updateOrInsert(
            [
                'email' => $record->email,
            ],
            $updateData,
        );

        return $data;
    }
}
