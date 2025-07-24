<?php
/*
Plugin Name: EVE Standings Calculator
Description: Adds a shortcode [eve_standings_calculator] to automatically calculate broker fees and reprocessing tax.
Version: 1
Author: C4813
*/

function eve_standings_calculator_shortcode() {
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
            font-weight: bold;
            text-align: center;
        }
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
        .skill-note {
            font-weight: bold;
            text-align: center;
        }
        .divider {
            margin-top: 30px;
            border-top: 1px solid #ccc;
        }
    </style>

    <div class="eve-standings-form">
        <div class="eve-row">
            <div class="eve-col">
                <label>Broker Relations Skill (0–5):</label>
                <input type="number" id="broker_skill" min="0" max="5" step="1" value="0">

                <label>Connections Skill (0–5):</label>
                <input type="number" id="connections_skill" min="0" max="5" step="1" value="0">

                <label>Diplomacy Skill (0–5):</label>
                <input type="number" id="diplomacy_skill" min="0" max="5" step="1" value="0">
            </div>
            <div class="eve-col">
                <div class="skill-note">Market Standings &<br /> Reprocessing Tax Calculator</div>
            </div>
        </div>

        <div class="divider"></div>

        <div class="eve-row" style="margin-top: 20px;">
            <div class="eve-col">
                <label>Derived Faction Standing:</label>
                <input type="number" id="jita_faction" step="0.01" min="-10" max="10" value="0">
                <label>Derived Corp Standing:</label>
                <input type="number" id="jita_corp" step="0.01" min="-10" max="10" value="0">
            </div>
            <div class="eve-col">
                <div class="output" id="jita_result"></div>
            </div>
        </div>
    </div>

    <script>
        function safeParse(val) {
            const parsed = parseFloat(val);
            return isNaN(parsed) ? 0 : parsed;
        }

        function applyDiplomacy(standing, diplo) {
            return standing < 0
                ? standing + ((10 - standing) * 0.04 * diplo)
                : standing;
        }

        function calcBrokerFee(skill, faction, corp) {
            return Math.max(0, 3 - (0.3 * skill) - (0.03 * faction) - (0.02 * corp));
        }

        function calcReprocessingTax(effective) {
            return Math.max(0, 5 * (1 - (effective / 6.67)));
        }

        function renderHubResult(faction, corp, brokerSkill, diploSkill) {
            const factionAdj = applyDiplomacy(faction, diploSkill);
            const corpAdj = applyDiplomacy(corp, diploSkill);
            const broker = calcBrokerFee(brokerSkill, factionAdj, corpAdj).toFixed(2);
            const reprocessing = calcReprocessingTax(Math.max(factionAdj, corpAdj)).toFixed(2);
            return `Broker Fee: <strong>${broker}%</strong><br>Reprocessing Tax: <strong>${reprocessing}%</strong>`;
        }

        function updateResults() {
            const brokerSkill = safeParse(document.getElementById('broker_skill').value);
            const diplomacySkill = safeParse(document.getElementById('diplomacy_skill').value);

            const faction = safeParse(document.getElementById('jita_faction').value);
            const corp = safeParse(document.getElementById('jita_corp').value);
            const resultText = renderHubResult(faction, corp, brokerSkill, diplomacySkill);
            document.getElementById('jita_result').innerHTML = resultText;
        }

        document.addEventListener('input', function(e) {
            if (e.target.closest('.eve-standings-form')) {
                updateResults();
            }
        });

        document.addEventListener('DOMContentLoaded', updateResults);
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode('eve_standings_calculator', 'eve_standings_calculator_shortcode');
