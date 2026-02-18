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
        $dayStart->setTime(
            (int)substr($workingHours['start'], 0, 2),
            (int)substr($workingHours['start'], 3, 2),
            0
        );

        $dayEnd = clone $date;
        $dayEnd->setTime(
            (int)substr($workingHours['end'], 0, 2),
            (int)substr($workingHours['end'], 3, 2),
            0
        );

        // Busy-Zeiten aus allen Kalender-Quellen sammeln
        $allBusySlots = $this->collectBusySlots($dayStart, $dayEnd);

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
     * Gibt verfügbare Tage in einem Monat zurück (hat mindestens einen freien Slot).
     * @return array ['2024-01-15' => true, '2024-01-16' => true, ...]
     */
    public function getAvailableDays(int $year, int $month): array
    {
        $tz = new \DateTimeZone($this->config['app']['timezone']);
        $available = [];

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = new \DateTime("{$year}-{$month}-{$day}", $tz);
            $slots = $this->getAvailableSlots(clone $date);
            $dateStr = $date->format('Y-m-d');
            if (!empty($slots)) {
                $available[$dateStr] = count($slots);
            }
        }

        return $available;
    }

    private function collectBusySlots(\DateTime $start, \DateTime $end): array
    {
        $allBusy = [];

        foreach ($this->calendarServices as $service) {
            $busy = $service->getFreeBusy($start, $end);
            $allBusy = array_merge($allBusy, $busy);
        }

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
     * Prüft ob zwei Zeiträume sich überlappen.
     */
    private function overlaps(\DateTime $start1, \DateTime $end1, \DateTime $start2, \DateTime $end2): bool
    {
        return $start1 < $end2 && $start2 < $end1;
    }
}
