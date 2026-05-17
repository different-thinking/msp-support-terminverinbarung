<?php
/**
 * Gemeinsames Template fuer das Buchungsformular.
 * Wird von index.php und embed.php eingebunden.
 *
 * Erwartet folgende Variablen:
 * - $duration (int)
 * - $additionalFields (array)
 * - $allowAttendees (bool)
 * - $maxAttendees (int)
 * - $organizerName (string)
 * - $bookingInfo (string, optional) – nur in index.php gesetzt
 */
?>

    <?php if (!empty($activeFunnelSlug)): ?>
    <input type="hidden" id="field-funnel" value="<?= htmlspecialchars($activeFunnelSlug) ?>">
    <?php endif; ?>

    <!-- Schrittanzeige -->
    <div class="booking-steps">
        <div class="step active" data-step="1">
            <span class="step-number">1</span>
            <span>Termin wählen</span>
        </div>
        <div class="step-connector"></div>
        <div class="step" data-step="2">
            <span class="step-number">2</span>
            <span>Daten eingeben</span>
        </div>
        <div class="step-connector"></div>
        <div class="step" data-step="3">
            <span class="step-number">3</span>
            <span>Bestätigen</span>
        </div>
    </div>

    <!-- Schritt 1: Kalender & Zeitslots -->
    <div id="panel-calendar" class="booking-panel active">
        <h2 class="panel-title">Wählen Sie Datum und Uhrzeit</h2>
        <?php if (!empty($bookingInfo)): ?>
        <p class="booking-info-text"><?= formatText($bookingInfo) ?></p>
        <?php endif; ?>

        <div class="calendar-time-wrapper">
            <div class="calendar-container">
                <div class="calendar-nav">
                    <button id="btn-prev-month" type="button" disabled>&larr;</button>
                    <span id="calendar-month-year" class="calendar-month-year"></span>
                    <button id="btn-next-month" type="button">&rarr;</button>
                </div>

                <div id="calendar-grid" class="calendar-grid"></div>
            </div>

            <div id="time-slots-container" class="time-slots-container hidden"></div>
        </div>

        <div class="btn-group">
            <span></span>
            <button id="btn-to-form" class="btn btn-primary" disabled>
                Weiter
            </button>
        </div>
    </div>

    <!-- Schritt 2: Formular -->
    <div id="panel-form" class="booking-panel">
        <h2 class="panel-title">Ihre Daten</h2>

        <div class="form-row">
            <div class="form-group">
                <label for="field-firstname">Vorname <span class="required">*</span></label>
                <input type="text" id="field-firstname" name="firstname" required placeholder="Ihr Vorname" maxlength="100">
                <div class="form-error"></div>
            </div>
            <div class="form-group">
                <label for="field-lastname">Nachname <span class="required">*</span></label>
                <input type="text" id="field-lastname" name="lastname" required placeholder="Ihr Nachname" maxlength="100">
                <div class="form-error"></div>
            </div>
        </div>

        <div class="form-group">
            <label for="field-email">E-Mail-Adresse <span class="required">*</span></label>
            <input type="email" id="field-email" name="email" required placeholder="ihre@email.de" maxlength="254">
            <div class="form-error"></div>
        </div>

        <?php foreach ($additionalFields as $field): ?>
        <div class="form-group">
            <label for="field-<?= htmlspecialchars($field['name']) ?>">
                <?= htmlspecialchars($field['label']) ?>
                <?php if (!empty($field['required'])): ?><span class="required">*</span><?php endif; ?>
            </label>
            <?php if ($field['type'] === 'textarea'): ?>
            <textarea
                id="field-<?= htmlspecialchars($field['name']) ?>"
                name="<?= htmlspecialchars($field['name']) ?>"
                class="additional-field"
                placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                maxlength="5000"
                <?php if (!empty($field['required'])): ?>data-required="true"<?php endif; ?>
            ></textarea>
            <?php else: ?>
            <input
                type="<?= htmlspecialchars($field['type'] ?? 'text') ?>"
                id="field-<?= htmlspecialchars($field['name']) ?>"
                name="<?= htmlspecialchars($field['name']) ?>"
                class="additional-field"
                placeholder="<?= htmlspecialchars($field['placeholder'] ?? '') ?>"
                maxlength="500"
                <?php if (!empty($field['required'])): ?>data-required="true"<?php endif; ?>
            >
            <?php endif; ?>
            <div class="form-error"></div>
        </div>
        <?php endforeach; ?>

        <?php if ($allowAttendees): ?>
        <div class="attendees-section">
            <h3>Weitere Teilnehmer hinzufügen (optional)</h3>
            <div id="attendees-list" data-max="<?= $maxAttendees ?>"></div>
            <button type="button" id="btn-add-attendee" class="btn-add-attendee">
                + Teilnehmer hinzufügen
            </button>
        </div>
        <?php endif; ?>

        <div class="btn-group">
            <button id="btn-back-to-calendar" class="btn btn-secondary" type="button">
                &larr; Zurück
            </button>
            <button id="btn-to-confirm" class="btn btn-primary" type="button">
                Weiter zur Bestätigung
            </button>
        </div>
    </div>

    <!-- Schritt 3: Bestätigung -->
    <div id="panel-confirm" class="booking-panel">
        <h2 class="panel-title">Termin bestätigen</h2>

        <div class="booking-summary">
            <h3>Zusammenfassung</h3>
            <div id="booking-summary-content"></div>
        </div>

        <div class="alert alert-info">
            Nach der Buchung erhalten Sie eine Outlook-Termineinladung direkt von <?= htmlspecialchars($organizerName) ?> mit einem Microsoft Teams Link.
        </div>

        <div class="btn-group">
            <button id="btn-back-to-form" class="btn btn-secondary" type="button">
                &larr; Zurück
            </button>
            <button id="btn-book" class="btn btn-primary" type="button">
                Termin verbindlich buchen
            </button>
        </div>
    </div>

    <!-- Erfolg -->
    <div id="panel-success" class="booking-panel">
        <div class="success-panel">
            <div class="success-icon">&#10003;</div>
            <h2>Termin gebucht!</h2>
            <p>Sie erhalten in Kürze eine Termineinladung per E-Mail.</p>
            <div class="success-details"></div>
        </div>
    </div>
