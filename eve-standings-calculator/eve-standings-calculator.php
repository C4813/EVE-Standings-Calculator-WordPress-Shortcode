<?php
/*
Plugin Name: EVE Standings Calculator
Description: Adds a shortcode [eve_standings_calculator] to calculate broker fees and reprocessing tax using standing + skill logic.
Version: 2.2.0
Author: C4813
*/

defined('ABSPATH') || exit; // Prevent direct access

// -------------------------------
// Utilities: safe JSON file load
// -------------------------------
if (!function_exists('ecs_load_json_file')) {
    /**
     * Load a JSON file from the plugin directory safely.
     * Returns an array (assoc or list) or an empty array on failure.
     *
     * @param string $filename Relative to this plugin file (e.g., 'corps.json')
     * @return array
     */
    function ecs_load_json_file($filename) {
        $path = plugin_dir_path(__FILE__) . ltrim($filename, '/');
        if (!is_readable($path)) {
            return array();
        }

        // Prefer WP helper (WP 5.9+)
        if (function_exists('wp_json_file_decode')) {
            $data = wp_json_file_decode($path, array('associative' => true));
            return (is_array($data)) ? $data : array();
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return array();
        }
        $data = json_decode($raw, true);
        return (is_array($data)) ? $data : array();
    }
}

// -------------------------------------------
// Shortcode: [eve_standings_calculator]
// -------------------------------------------
add_shortcode('eve_standings_calculator', function () {
    // Load JSON datasets (trusted local files)
    $corps            = ecs_load_json_file('corps.json');              // list
    $corp_to_faction  = ecs_load_json_file('corp_to_faction.json');    // map
    $faction_skills   = ecs_load_json_file('faction_skills.json');     // map
    $corp_skills      = ecs_load_json_file('corp_skills.json');        // map

    // Fail-closed if core datasets are missing
    $datasets_ok = !empty($corps) && !empty($corp_to_faction) && !empty($faction_skills) && !empty($corp_skills);
    if (!$datasets_ok) {
        return '<div class="eve-standings-form"><strong>Standings data is unavailable.</strong></div>';
    }

    // Register a dummy script/style handle and enqueue only when shortcode is used
    $handle_js  = 'ecs-standings-calc';
    $handle_css = 'ecs-standings-style';

    // Enqueue empty handles
    wp_register_script($handle_js, false, array(), '2.3.0', true);
    wp_enqueue_script($handle_js);

    wp_register_style($handle_css, false, array(), '2.3.0');
    wp_enqueue_style($handle_css);

    // Pass data to JS safely (before script so it's available)
    $data = array(
        'corps'           => array_values($corps), // ensure numeric indexes
        'corpFactionMap'  => $corp_to_faction,
        'factionSkillMap' => $faction_skills,
        'corpSkillMap'    => $corp_skills,
        'defaults' => array(
            'defaultCorp'    => 'Caldari Navy',
            'fallbackFaction'=> 'Caldari State',
        ),
    );
    wp_add_inline_script(
        $handle_js,
        'window.ECS_DATA = ' . wp_json_encode($data) . ';',
        'before'
    );

    // Add CSS inline (CSP-friendly via handle; small enough to inline)
    $css = <<<CSS
.eve-standings-form{max-width:800px;margin:0 auto;padding:20px;border:1px solid #ccc;border-radius:8px}
.eve-standings-form label{display:block;margin-top:10px;text-align:center;font-weight:400}
.eve-standings-form select,.eve-standings-form input[type="number"]{width:200px;padding:6px;margin:4px auto 0 auto;display:block;text-align-last:center}
.eve-row{display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:20px}
.eve-col{text-align:center}
.eve-col .output{padding:12px;background:#f9f9f9;border-radius:6px;border:1px solid #ddd;display:inline-block}
.divider{margin-top:30px;border-top:1px solid #ccc}
.title-lg{font-size:18px;font-weight:700;margin-bottom:4px}
.eve-output-line{margin:2px 0}
CSS;
    wp_add_inline_style($handle_css, $css);

    // Main JS (strict mode, scoped listeners, clamping, no innerHTML)
    $js = <<<JS
(function() {
    "use strict";

    // Pull data then remove global reference
    const RAW = window.ECS_DATA || {};
    const DATA = Object.freeze({
        corps: Array.isArray(RAW.corps) ? Object.freeze(RAW.corps.slice(0)) : Object.freeze([]),
        corpFactionMap: RAW.corpFactionMap || {},
        factionSkillMap: RAW.factionSkillMap || {},
        corpSkillMap: RAW.corpSkillMap || {},
        defaults: RAW.defaults || {}
    });
    try { delete window.ECS_DATA; } catch(e) { window.ECS_DATA = undefined; }

    // Helpers
    const clamp = (val, min, max) => Math.min(Math.max(val, min), max);
    const safeParse = (val) => {
        const p = parseFloat(val);
        return (Number.isFinite(p)) ? p : 0;
    };
    const hasOwn = (o,k) => Object.prototype.hasOwnProperty.call(o,k);
    const mget = (o,k,fb) => hasOwn(o,k) ? o[k] : fb;

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = String(value);
    }

    function populateDropdown(id, options, selected) {
        const select = document.getElementById(id);
        if (!select) return;
        select.innerHTML = '';
        (options || []).forEach(opt => {
            const o = document.createElement('option');
            o.value = opt;
            o.textContent = opt;
            if (opt === selected) o.selected = true;
            select.appendChild(o);
        });
    }

    function getFactionByCorp(corpName) {
        return mget(DATA.corpFactionMap, corpName, DATA.defaults.fallbackFaction || 'Caldari State');
    }

    function applyEffectiveStanding(base, skillType, connSkill, crimSkill, diploSkill) {
        if (base === 0) return 0;
        if (base < 0) return base + ((10 - base) * 0.04 * diploSkill);
        const level = (skillType === 'Criminal Connections') ? crimSkill : connSkill;
        return base + ((10 - base) * 0.04 * level);
    }

    function calcBrokerFee(brokerSkill, factionAdj, corpAdj) {
        return Math.max(0, 3 - (0.3 * brokerSkill) - (0.03 * factionAdj) - (0.02 * corpAdj));
    }

    function calcReprocessingTax(effectiveCorpAdj) {
        return Math.max(0, 5 * (1 - (effectiveCorpAdj / 6.67)));
    }

    function updateResults() {
        // Skills [0..5], clamp & reflect if needed later
        const brokerSkill = clamp(safeParse(document.getElementById('broker_skill').value), 0, 5);
        const connSkill   = clamp(safeParse(document.getElementById('connections_skill').value), 0, 5);
        const crimSkill   = clamp(safeParse(document.getElementById('criminal_connections_skill').value), 0, 5);
        const diploSkill  = clamp(safeParse(document.getElementById('diplomacy_skill').value), 0, 5);

        // Standings [-10..10], clamp and write back to inputs
        const factionInput = document.getElementById('faction_standing');
        const corpInput    = document.getElementById('corp_standing');

        let factionStanding = clamp(safeParse(factionInput.value), -10, 10);
        let corpStanding    = clamp(safeParse(corpInput.value),    -10, 10);

        factionInput.value = factionStanding;
        corpInput.value    = corpStanding;

        // Resolve corp -> faction and skills
        const corpName    = (document.getElementById('corp_select').value || '').trim();
        const factionName = getFactionByCorp(corpName);
        setText('faction_display', factionName);

        const factionSkill = mget(DATA.factionSkillMap, factionName, 'Connections');
        const corpSkill    = mget(DATA.corpSkillMap,    corpName,    'Connections');

        // Effective standings
        const factionAdj = applyEffectiveStanding(factionStanding, factionSkill, connSkill, crimSkill, diploSkill);
        const corpAdj    = applyEffectiveStanding(corpStanding,    corpSkill,    connSkill, crimSkill, diploSkill);

        // Calculations
        const broker       = calcBrokerFee(brokerSkill, factionAdj, corpAdj).toFixed(2);
        const reprocessing = calcReprocessingTax(corpAdj).toFixed(2);

        // Which skill actually applied (Diplomacy overrides when base < 0)
        const skillUsedFaction = (factionStanding < 0) ? 'Diplomacy' : factionSkill;
        const skillUsedCorp    = (corpStanding    < 0) ? 'Diplomacy' : corpSkill;

        // Update UI
        setText('broker_value', broker + '%');
        setText('reprocessing_value', reprocessing + '%');
        setText('skill_faction_value', skillUsedFaction);
        setText('skill_corp_value', skillUsedCorp);
        setText('derived_faction_standing', factionAdj.toFixed(2));
        setText('derived_corp_standing',    corpAdj.toFixed(2));
    }

    function init() {
        populateDropdown('corp_select', DATA.corps, (DATA.defaults && DATA.defaults.defaultCorp) || 'Caldari Navy');

        // Scope event listeners to this form, not the whole document
        const form = document.querySelector('.eve-standings-form');
        if (form) {
            form.addEventListener('input', updateResults, { passive: true });
            form.addEventListener('change', updateResults);
        }

        updateResults();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
    wp_add_inline_script($handle_js, $js);

    // --- Markup (static; JS fills in values via textContent) ---
    ob_start();
    ?>
    <div class="eve-standings-form">
        <div class="eve-row">
            <div class="eve-col">
                <label class="title-lg">Skills</label>

                <label>Broker Relations</label>
                <select id="broker_skill">
                    <?php for ($i = 0; $i <= 5; $i++) : ?>
                        <option value="<?php echo (int)$i; ?>" <?php selected($i, 5); ?>><?php echo (int)$i; ?></option>
                    <?php endfor; ?>
                </select>

                <label>Connections</label>
                <select id="connections_skill">
                    <?php for ($i = 0; $i <= 5; $i++) : ?>
                        <option value="<?php echo (int)$i; ?>" <?php selected($i, 5); ?>><?php echo (int)$i; ?></option>
                    <?php endfor; ?>
                </select>

                <label>Criminal Connections</label>
                <select id="criminal_connections_skill">
                    <?php for ($i = 0; $i <= 5; $i++) : ?>
                        <option value="<?php echo (int)$i; ?>" <?php selected($i, 5); ?>><?php echo (int)$i; ?></option>
                    <?php endfor; ?>
                </select>

                <label>Diplomacy</label>
                <select id="diplomacy_skill">
                    <?php for ($i = 0; $i <= 5; $i++) : ?>
                        <option value="<?php echo (int)$i; ?>" <?php selected($i, 5); ?>><?php echo (int)$i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="eve-col">
                <div class="output title-lg" style="font-weight:700;">Market Standings &amp;<br> Reprocessing Tax Calculator</div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="eve-row" style="margin-top:20px;">
            <div class="eve-col">
                <label class="title-lg">Standings</label>

                <label>Faction</label>
                <div id="faction_display" class="output"></div>

                <label><strong>Base</strong> Faction Standing</label>
                <input type="number" id="faction_standing" step="0.01" min="-10" max="10" value="0" inputmode="decimal">
                <div style="margin-top:4px;"><i>Effective: <span id="derived_faction_standing">0.00</span></i></div>

                <label>Corporation</label>
                <select id="corp_select" style="width:300px;"></select>

                <label><strong>Base</strong> Corp Standing</label>
                <input type="number" id="corp_standing" step="0.01" min="-10" max="10" value="0" inputmode="decimal">
                <div style="margin-top:4px;"><i>Effective: <span id="derived_corp_standing">0.00</span></i></div>
            </div>

            <div class="eve-col">
                <div style="display:flex;flex-direction:column;align-items:center;gap:12px;">
                    <div class="output">
                        <div class="eve-output-line"><strong>Brokerage Fee:</strong> <span id="broker_value">0.00%</span></div>
                        <div class="eve-output-line"><strong>Reprocessing Tax:</strong> <span id="reprocessing_value">0.00%</span></div>
                    </div>
                    <div class="output">
                        <div class="title-lg">Skills Used</div>
                        <div class="eve-output-line"><strong>Faction:</strong> <span id="skill_faction_value">Connections</span></div>
                        <div class="eve-output-line"><strong>Corporation:</strong> <span id="skill_corp_value">Connections</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
});
