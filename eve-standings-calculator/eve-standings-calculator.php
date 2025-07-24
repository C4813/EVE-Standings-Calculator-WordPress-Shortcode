<?php
/*
Plugin Name: EVE Standings Calculator
Description: Adds a shortcode [eve_standings_calculator] to calculate broker fees and reprocessing tax using standing + skill logic.
Version: 2
Author: C4813
*/

function eve_standings_calculator_shortcode() {
    $faction_json = file_get_contents(__DIR__ . '/factions.json');
    $corp_faction_json = file_get_contents(__DIR__ . '/corp_to_faction.json');
    $corp_json = file_get_contents(__DIR__ . '/corps.json');
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
    }
        .eve-standings-form select,
        .eve-standings-form input[type="number"] {
            width: 200px;
            padding: 6px;
            margin: 4px auto 0 auto;
            display: block;
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
                <label style="font-weight: normal;">Broker Relations</label>
                <select id="broker_skill" style="text-align-last: center;">
                    <option value="0">0</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                </select>

                <label style="font-weight: normal;">Connections</label>
                <select id="connections_skill" style="text-align-last: center;">
                    <option value="0">0</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                </select>

                <label style="font-weight: normal;">Criminal Connections</label>
                <select id="criminal_connections_skill" style="text-align-last: center;">
                    <option value="0">0</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                </select>

                <label style="font-weight: normal;">Diplomacy</label>
                <select id="diplomacy_skill" style="text-align-last: center;">
                    <option value="0">0</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                </select>
            </div>
            <div class="eve-col">
                <div class="output" style="font-size: 18px; font-weight: bold; margin-bottom: 4px;">Market Standings &<br /> Reprocessing Tax Calculator</div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="eve-row" style="margin-top: 20px;">
            <div class="eve-col">
                <label style="font-size: 18px; font-weight: bold; margin-bottom: 4px;">Standings</label>
                <label style="font-weight: normal;">Faction</label>
                    <div id="faction_display" class="output"></div>
                <label style="font-weight: normal;">Derived Faction Standing</label>
                <input type="number" id="faction_standing" step="0.01" min="-10" max="10" value="0">

                <label style="font-weight: normal;">Corporation</label>
                <select id="corp_select" style="width: 300px; text-align-last: center;"></select>
                <label style="font-weight: normal;">Derived Corp Standing</label>
                <input type="number" id="corp_standing" step="0.01" min="-10" max="10" value="0">
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
        function getFactionByCorp(corpName) {
            return corpFactionMap[corpName] || "Caldari State";
        }
                const corpList = <?= $corp_json ?>;
        const corpFactionMap = <?= $corp_faction_json ?>;
        const factionSkillMap = <?= $faction_skill_json ?>;
        const corpSkillMap = <?= $corp_skill_json ?>;

        function populateDropdown(id, options) {
            const select = document.getElementById(id);
            options.forEach(opt => {
                const option = document.createElement("option");
                option.value = opt;
                option.textContent = opt;
                select.appendChild(option);
            });
        }

                populateDropdown("corp_select", corpList);
        const initialCorp = document.getElementById('corp_select').value;
        document.getElementById('faction_display').textContent = getFactionByCorp(initialCorp);
        document.getElementById("corp_select").addEventListener("change", () => {
            const corpName = document.getElementById('corp_select').value;
            const factionName = getFactionByCorp(corpName);
            document.getElementById('faction_display').textContent = factionName;
            updateResults();
        });

        function safeParse(val) {
            const parsed = parseFloat(val);
            return isNaN(parsed) ? 0 : parsed;
        }

        function applyEffectiveStanding(standing, skillType, connSkill, crimSkill, diploSkill) {
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
            const reprocessing = calcReprocessingTax(Math.max(factionAdj, corpAdj)).toFixed(2);

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
        }

        document.addEventListener('input', updateResults);
        document.addEventListener('change', updateResults);
        document.addEventListener('DOMContentLoaded', updateResults);
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode('eve_standings_calculator', 'eve_standings_calculator_shortcode');
