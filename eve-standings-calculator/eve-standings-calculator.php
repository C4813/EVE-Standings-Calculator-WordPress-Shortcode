<?php
/*
Plugin Name: EVE Standings Calculator
Description: Adds a shortcode [eve_standings_calculator] to calculate broker fees and reprocessing tax using standing + skill logic.
Version: 2.1.3
Author: C4813
*/

function eve_standings_calculator_shortcode() {
    $corp_json = file_get_contents(__DIR__ . '/corps.json');
    $corp_faction_json = file_get_contents(__DIR__ . '/corp_to_faction.json');
    $faction_skill_json = file_get_contents(__DIR__ . '/faction_skills.json');
    $corp_skill_json = file_get_contents(__DIR__ . '/corp_skills.json');

    ob_start(); ?>

    <style>
        .eve-standings-form {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 8px;
        }
        .eve-standings-form label {
            display: block;
            margin-top: 10px;
            text-align: center;
            font-weight: normal;
        }
        .eve-standings-form select,
        .eve-standings-form input[type="number"] {
            width: 200px;
            padding: 6px;
            margin: 4px auto 0 auto;
            display: block;
            text-align-last: center;
        }
        .eve-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            align-items: center;
            gap: 20px;
        }
        .eve-col {
            text-align: center;
        }
        .eve-col .output {
            padding: 12px;
            background: #f9f9f9;
            border-radius: 6px;
            border: 1px solid #ddd;
            display: inline-block;
        }
        .divider {
            margin-top: 30px;
            border-top: 1px solid #ccc;
        }
    </style>

    <div class="eve-standings-form">
        <div class="eve-row">
            <div class="eve-col">
                <label style="font-size: 18px; font-weight: bold; margin-bottom: 4px;">Skills</label>
                <?php
                $skills = ['broker_skill' => 'Broker Relations', 'connections_skill' => 'Connections', 'criminal_connections_skill' => 'Criminal Connections', 'diplomacy_skill' => 'Diplomacy'];
                foreach ($skills as $id => $label) {
                    echo "<label>{$label}</label><select id=\"{$id}\">";
                    for ($i = 0; $i <= 5; $i++) {
                        $selected = ($i === 5) ? 'selected' : '';
                        echo "<option value=\"$i\" $selected>$i</option>";
                    }
                    echo "</select>";
                }
                ?>
            </div>
            <div class="eve-col">
                <div class="output" style="font-size: 18px; font-weight: bold; margin-bottom: 4px;">Market Standings &<br /> Reprocessing Tax Calculator</div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="eve-row" style="margin-top: 20px;">
            <div class="eve-col">
                <label style="font-size: 18px; font-weight: bold; margin-bottom: 4px;">Standings</label>
                <label>Faction</label>
                <div id="faction_display" class="output"></div>
                <label><strong>Base</strong> Faction Standing</label>
                <input type="number" id="faction_standing" step="0.01" min="-10" max="10" value="0">
                <div style="margin-top: 4px;"><i>Effective: <span id="derived_faction_standing">0.00</span></i></div>

                <label>Corporation</label>
                <select id="corp_select" style="width: 300px;"></select>
                <label><strong>Base</strong> Corp Standing</label>
                <input type="number" id="corp_standing" step="0.01" min="-10" max="10" value="0">
                <div style="margin-top: 4px;"><i>Effective: <span id="derived_corp_standing">0.00</span></i></div>
            </div>
            <div class="eve-col">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                    <div class="output" id="result_main"></div>
                    <div class="output" id="result_skills"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const corpList = <?= $corp_json ?>;
        const corpFactionMap = <?= $corp_faction_json ?>;
        const factionSkillMap = <?= $faction_skill_json ?>;
        const corpSkillMap = <?= $corp_skill_json ?>;

        function getFactionByCorp(corpName) {
            return corpFactionMap[corpName] || "Caldari State";
        }

        function populateDropdown(id, options, defaultValue = "") {
            const select = document.getElementById(id);
            options.forEach(opt => {
                const option = document.createElement("option");
                option.value = opt;
                option.textContent = opt;
                if (opt === defaultValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        }

        populateDropdown("corp_select", corpList, "Caldari Navy");

        function safeParse(val) {
            const parsed = parseFloat(val);
            return isNaN(parsed) ? 0 : parsed;
        }

        function applyEffectiveStanding(standing, skillType, connSkill, crimSkill, diploSkill) {
            if (standing === 0) return 0;
            if (standing < 0) {
                return standing + ((10 - standing) * 0.04 * diploSkill);
            } else {
                const skillLevel = (skillType === "Criminal Connections") ? crimSkill : connSkill;
                return standing + ((10 - standing) * 0.04 * skillLevel);
            }
        }

        function calcBrokerFee(skill, faction, corp) {
            return Math.max(0, 3 - (0.3 * skill) - (0.03 * faction) - (0.02 * corp));
        }

        function calcReprocessingTax(effective) {
            return Math.max(0, 5 * (1 - (effective / 6.67)));
        }

        function updateResults() {
            const brokerSkill = safeParse(document.getElementById('broker_skill').value);
            const connSkill = safeParse(document.getElementById('connections_skill').value);
            const crimSkill = safeParse(document.getElementById('criminal_connections_skill').value);
            const diploSkill = safeParse(document.getElementById('diplomacy_skill').value);
            const factionStanding = safeParse(document.getElementById('faction_standing').value);
            const corpStanding = safeParse(document.getElementById('corp_standing').value);
            const corpName = document.getElementById('corp_select').value;
            const factionName = getFactionByCorp(corpName);
            document.getElementById('faction_display').textContent = factionName;

            const factionSkill = factionSkillMap[factionName] || "Connections";
            const corpSkill = corpSkillMap[corpName] || "Connections";

            const factionAdj = applyEffectiveStanding(factionStanding, factionSkill, connSkill, crimSkill, diploSkill);
            const corpAdj = applyEffectiveStanding(corpStanding, corpSkill, connSkill, crimSkill, diploSkill);

            const broker = calcBrokerFee(brokerSkill, factionAdj, corpAdj).toFixed(2);
            const reprocessing = calcReprocessingTax(corpAdj).toFixed(2);

            const skillUsedFaction = (factionStanding < 0) ? "Diplomacy" : factionSkill;
            const skillUsedCorp = (corpStanding < 0) ? "Diplomacy" : corpSkill;

            document.getElementById('result_main').innerHTML = `
                <strong>Brokerage Fee:</strong> ${broker}%<br>
                <strong>Reprocessing Tax:</strong> ${reprocessing}%
            `;
            document.getElementById('result_skills').innerHTML = `
                <strong>Skill Used (Faction)</strong><br><i>${skillUsedFaction}</i><br>
                <strong>Skill Used (Corp)</strong><br><i>${skillUsedCorp}</i>
            `;

            document.getElementById('derived_faction_standing').textContent = factionAdj.toFixed(2);
            document.getElementById('derived_corp_standing').textContent = corpAdj.toFixed(2);
        }

        document.addEventListener('input', updateResults);
        document.addEventListener('change', updateResults);
        document.addEventListener('DOMContentLoaded', updateResults);
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode('eve_standings_calculator', 'eve_standings_calculator_shortcode');
