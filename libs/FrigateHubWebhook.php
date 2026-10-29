<?php

declare(strict_types=1);

/**
 * WebHook-Eintrag beim WebHook Control anlegen und beim Löschen der Instanz wieder entfernen —
 * gemeinsam für Kamera und Server.
 *
 * Destroy() ruft Symcon auch beim Beenden auf; dann existiert die Instanz noch und der Eintrag
 * muss bleiben. Entfernt wird deshalb nur, wenn der Kernel läuft und die Instanz wirklich weg ist.
 */
trait FrigateHubWebhook
{
    private static string $FRIGATEHUB_WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    /** Eintrag „$pfad → diese Instanz" sicherstellen (Standard-Muster, wie NRGDashboardTile). */
    private function webhookEintragen(string $pfad): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::$FRIGATEHUB_WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
        $hooks = is_array($hooks) ? $hooks : [];
        foreach ($hooks as $i => $hook) {
            if (($hook['Hook'] ?? '') === $pfad) {
                if ((int)$hook['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $pfad, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    /** In Destroy() aufrufen: Eintrag entfernen, aber nur wenn die Instanz gelöscht wurde. */
    private function webhookAufraeumen(string $pfad): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY || IPS_InstanceExists($this->InstanceID)) {
            return;
        }
        $ids = IPS_GetInstanceListByModuleID(self::$FRIGATEHUB_WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
        $hooks = is_array($hooks) ? $hooks : [];
        $neu = array_values(array_filter($hooks, fn ($h) => !(($h['Hook'] ?? '') === $pfad && (int)($h['TargetID'] ?? 0) === $this->InstanceID)));
        if (count($neu) !== count($hooks)) {
            IPS_SetProperty($ids[0], 'Hooks', json_encode($neu));
            IPS_ApplyChanges($ids[0]);
        }
    }
}
