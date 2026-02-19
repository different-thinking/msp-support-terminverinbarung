<?php

/**
 * Berechnet die Verfügbarkeit basierend auf mehreren Kalender-Quellen.
 * Merged Busy-Zeiten und erzeugt buchbare Zeitslots.
 */
class AvailabilityEngine
{
    private array $config;
    private array $calendarServices;

    public function __construct(array $config, array $calendarServices)
    {
        $this->config = $config;
        $this->calendarServices = $calendarServices;
    }

    /**
     * Gibt verfügbare Zeitslots für ein Datum zurück.
     * @return array [['start' => 'H:i', 'end' => 'H:i', 'datetime_start' => DateTime, 'datetime_end' => DateTime], ...]
     */
    public function getAvailableSlots(\DateTime $date): array
    {
        $tz = new \DateTimeZone($this->config['app']['timezone']);
        $date->setTimezone($tz);

        // Arbeitszeiten für diesen Wochentag
        $dayName = strtolower($date->format('l'));
        $workingHours = $this->config['working_hours'][$dayName] ?? null;

        if (!$workingHours) {
            return []; // Kein Arbeitstag
        }

        $dayStart = clone $date;
        [$sh, $sm] = $this->parseTime($workingHours['start']);
        $dayStart->setTime($sh, $sm, 0);

        $dayEnd = clone $date;
        [$eh, $em] = $this->parseTime($workingHours['end']);
        $dayEnd->setTime($eh, $em, 0);

        // Busy-Zeiten aus allen Kalender-Quellen sammeln
        $allBusySlots = $this->collectBusySlots($dayStart, $dayEnd);

        // Pausenzeit als Busy-Slot hinzufügen
        $breakTime = $this->config['break_time'] ?? null;
        if ($breakTime && !empty($breakTime['enabled'])) {
            [$bsh, $bsm] = $this->parseTime($breakTime['start']);
            $breakStart = clone $date;
            $breakStart->setTime($bsh, $bsm, 0);
            [$beh, $bem] = $this->parseTime($breakTime['end']);
            $breakEnd = clone $date;
            $breakEnd->setTime($beh, $bem, 0);
            $allBusySlots[] = ['start' => $breakStart, 'end' => $breakEnd];
        }

        // Busy-Zeiten zusammenführen und überlappende mergen
        $mergedBusy = $this->mergeBusySlots($allBusySlots);

        // Verfügbare Slots berechnen
        $slots = $this->calculateFreeSlots($dayStart, $dayEnd, $mergedBusy);

        // Mindestvorlaufzeit prüfen
        $now = new \DateTime('now', $tz);
        $minNotice = clone $now;
        $minNotice->modify('+' . $this->config['app']['min_notice_hours'] . ' hours');

        $slots = array_filter($slots, function ($slot) use ($minNotice) {
            return $slot['datetime_start'] >= $minNotice;
        });

        return array_values($slots);
    }

    /**
     * Prueft ob ein konkreter Zeitslot verfuegbar ist.
     * Effizienter als getAvailableSlots(): nur ein gezielter Free/Busy-Check.
     */
    public function isSlotAvailable(\DateTime $start, \DateTime $end): bool
    {
        $tz = new \DateTimeZone($this->config['app']['timezone']);

        // Arbeitszeiten pruefen
        $dayName = strtolower($start->format('l'));
        $workingHours = $this->config['working_hours'][$dayName] ?? null;
        if (!$workingHours) {
            return false;
        }

        [$sh, $sm] = $this->parseTime($workingHours['start']);
        $dayStart = clone $start;
        $dayStart->setTime($sh, $sm, 0);

        [$eh, $em] = $this->parseTime($workingHours['end']);
        $dayEnd = clone $start;
        $dayEnd->setTime($eh, $em, 0);

        if ($start < $dayStart || $end > $dayEnd) {
            return false;
        }

        // Mindestvorlaufzeit pruefen
        $now = new \DateTime('now', $tz);
        $minNotice = clone $now;
        $minNotice->modify('+' . $this->config['app']['min_notice_hours'] . ' hours');
        if ($start < $minNotice) {
            return false;
        }

        // Pausenzeit pruefen
        $breakTime = $this->config['break_time'] ?? null;
        if ($breakTime && !empty($breakTime['enabled'])) {
            [$bsh, $bsm] = $this->parseTime($breakTime['start']);
            $breakStart = clone $start;
            $breakStart->setTime($bsh, $bsm, 0);
            [$beh, $bem] = $this->parseTime($breakTime['end']);
            $breakEnd = clone $start;
            $breakEnd->setTime($beh, $bem, 0);
            if ($this->overlaps($start, $end, $breakStart, $breakEnd)) {
                return false;
            }
        }

        // Busy-Slots nur fuer diesen konkreten Zeitraum abrufen
        $busySlots = $this->collectBusySlots($start, $end);
        foreach ($busySlots as $busy) {
            if ($this->overlaps($start, $end, $busy['start'], $busy['end'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gibt verfügbare Tage in einem Monat zurück (hat mindestens einen freien Slot).
     * Optimiert: Busy-Slots werden einmal für den gesamten Monat abgefragt
     * statt für jeden Tag einzeln (1 API-Call statt ~30).
     * @return array ['2024-01-15' => 5, '2024-01-16' => 3, ...]
     */
    public function getAvailableDays(int $year, int $month): array
    {
        $tz = new \DateTimeZone($this->config['app']['timezone']);
        $available = [];

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        // Busy-Slots einmal für den gesamten Monat abrufen
        $monthStart = new \DateTime("{$year}-{$month}-01 00:00:00", $tz);
        $monthEnd = clone $monthStart;
        $monthEnd->modify("+{$daysInMonth} days");

        $allBusySlots = $this->collectBusySlots($monthStart, $monthEnd);

        // Pausenzeiten vorbereiten (werden pro Tag genutzt)
        $breakTime = $this->config['break_time'] ?? null;
        $breakEnabled = $breakTime && !empty($breakTime['enabled']);

        // Mindestvorlaufzeit einmal berechnen
        $now = new \DateTime('now', $tz);
        $minNotice = clone $now;
        $minNotice->modify('+' . $this->config['app']['min_notice_hours'] . ' hours');

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = new \DateTime("{$year}-{$month}-{$day}", $tz);
            $dateStr = $date->format('Y-m-d');

            // Arbeitszeiten für diesen Wochentag
            $dayName = strtolower($date->format('l'));
            $workingHours = $this->config['working_hours'][$dayName] ?? null;

            if (!$workingHours) {
                continue; // Kein Arbeitstag
            }

            [$sh, $sm] = $this->parseTime($workingHours['start']);
            $dayStart = clone $date;
            $dayStart->setTime($sh, $sm, 0);

            [$eh, $em] = $this->parseTime($workingHours['end']);
            $dayEnd = clone $date;
            $dayEnd->setTime($eh, $em, 0);

            // Nur Busy-Slots dieses Tages filtern
            $dayBusy = array_filter($allBusySlots, function ($busy) use ($dayStart, $dayEnd) {
                return $busy['start'] < $dayEnd && $busy['end'] > $dayStart;
            });

            // Pausenzeit als Busy-Slot hinzufuegen
            if ($breakEnabled) {
                [$bsh, $bsm] = $this->parseTime($breakTime['start']);
                $breakStart = clone $date;
                $breakStart->setTime($bsh, $bsm, 0);
                [$beh, $bem] = $this->parseTime($breakTime['end']);
                $breakEnd = clone $date;
                $breakEnd->setTime($beh, $bem, 0);
                $dayBusy[] = ['start' => $breakStart, 'end' => $breakEnd];
            }

            $mergedBusy = $this->mergeBusySlots(array_values($dayBusy));
            $slots = $this->calculateFreeSlots($dayStart, $dayEnd, $mergedBusy);

            // Mindestvorlaufzeit prüfen
            $slots = array_filter($slots, function ($slot) use ($minNotice) {
                return $slot['datetime_start'] >= $minNotice;
            });

            if (!empty($slots)) {
                $available[$dateStr] = count($slots);
            }
        }

        return $available;
    }

    /**
     * Sammelt Busy-Slots aus allen Kalender-Quellen parallel via curl_multi.
     */
    private function collectBusySlots(\DateTime $start, \DateTime $end): array
    {
        // Alle curl-Handles + zugehörige Services sammeln
        $requests = [];
        foreach ($this->calendarServices as $service) {
            $handles = $service->prepareFreeBusyCurl($start, $end);
            foreach ($handles as $entry) {
                $requests[] = [
                    'handle' => $entry['handle'],
                    'service' => $service,
                ];
            }
        }

        if (empty($requests)) {
            return [];
        }

        // Bei nur einem Request kein curl_multi noetig
        if (count($requests) === 1) {
            $req = $requests[0];
            $response = curl_exec($req['handle']);
            if ($response === false) {
                error_log('Calendar Free/Busy curl error: ' . curl_error($req['handle']));
                curl_close($req['handle']);
                return [];
            }
            curl_close($req['handle']);
            return $req['service']->parseFreeBusyResponse($response);
        }

        // Parallel ausfuehren
        $mh = curl_multi_init();
        foreach ($requests as $req) {
            curl_multi_add_handle($mh, $req['handle']);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status === CURLM_OK);

        // Ergebnisse sammeln und parsen
        $allBusy = [];
        foreach ($requests as $req) {
            $errno = curl_errno($req['handle']);
            if ($errno !== 0) {
                error_log('Calendar Free/Busy curl_multi error: ' . curl_error($req['handle']));
                curl_multi_remove_handle($mh, $req['handle']);
                curl_close($req['handle']);
                continue;
            }
            $response = curl_multi_getcontent($req['handle']);
            $allBusy = array_merge(
                $allBusy,
                $req['service']->parseFreeBusyResponse($response ?: '')
            );
            curl_multi_remove_handle($mh, $req['handle']);
            curl_close($req['handle']);
        }

        curl_multi_close($mh);

        return $allBusy;
    }

    /**
     * Merged überlappende Busy-Zeiträume.
     */
    private function mergeBusySlots(array $busySlots): array
    {
        if (empty($busySlots)) {
            return [];
        }

        // Nach Startzeit sortieren
        usort($busySlots, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $merged = [$busySlots[0]];

        for ($i = 1; $i < count($busySlots); $i++) {
            $last = &$merged[count($merged) - 1];
            $current = $busySlots[$i];

            if ($current['start'] <= $last['end']) {
                // Überlappung - Ende auf Maximum setzen
                if ($current['end'] > $last['end']) {
                    $last['end'] = $current['end'];
                }
            } else {
                $merged[] = $current;
            }
        }

        return $merged;
    }

    /**
     * Berechnet freie Slots zwischen Busy-Zeiten.
     */
    private function calculateFreeSlots(\DateTime $dayStart, \DateTime $dayEnd, array $busySlots): array
    {
        $duration = $this->config['app']['appointment_duration_minutes'];
        $interval = $this->config['app']['slot_interval_minutes'];
        $slots = [];

        $cursor = clone $dayStart;

        while ($cursor < $dayEnd) {
            $slotEnd = clone $cursor;
            $slotEnd->modify("+{$duration} minutes");

            // Slot muss innerhalb der Arbeitszeiten liegen
            if ($slotEnd > $dayEnd) {
                break;
            }

            // Prüfen ob Slot mit Busy-Zeit kollidiert
            $isFree = true;
            foreach ($busySlots as $busy) {
                if ($this->overlaps($cursor, $slotEnd, $busy['start'], $busy['end'])) {
                    $isFree = false;
                    break;
                }
            }

            if ($isFree) {
                $slots[] = [
                    'start' => $cursor->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                    'datetime_start' => clone $cursor,
                    'datetime_end' => clone $slotEnd,
                ];
            }

            $cursor->modify("+{$interval} minutes");
        }

        return $slots;
    }

    /**
     * Parsed einen Zeitstring (z.B. "9:00", "09:00", "17:30") in [Stunde, Minute].
     * Robuster als substr() – funktioniert mit und ohne fuehrende Null.
     *
     * @return array{0: int, 1: int}
     */
    private function parseTime(string $time): array
    {
        $parts = explode(':', $time);
        return [
            (int)($parts[0] ?? 0),
            (int)($parts[1] ?? 0),
        ];
    }

    /**
     * Prüft ob zwei Zeiträume sich überlappen.
     */
    private function overlaps(\DateTime $start1, \DateTime $end1, \DateTime $start2, \DateTime $end2): bool
    {
        return $start1 < $end2 && $start2 < $end1;
    }
}
