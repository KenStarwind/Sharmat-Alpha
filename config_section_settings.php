        <!-- Settings Tab -->
        <div id="settings" class="tab-content">
            <div class="alert success" id="settingsSuccessAlert"></div>
            <div class="alert error" id="settingsErrorAlert"></div>

            <h2 class="section-header" style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 32px; height: 32px; vertical-align: middle; position: relative; top: -5px;"> General Settings</h2>

            <!-- OPEN MODE: top-level, across from the General Settings header -->
            <div data-unsaved-section style="padding: 15px 20px; background: #1C1A24; border: 1px solid #3A3545; border-radius: 8px; margin-bottom: 14px;">
                <div class="settings-checkbox-group" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                    <div style="flex: 1;">
                        <label for="nsfwOpenMode">
                            <input type="checkbox" id="nsfwOpenMode" name="NSFW_OPEN_MODE">
                            <span>Open Mode (free play)</span>
                        </label>
                        <p class="legend">Turns the consent/eligibility framework off: no relationship-type checks, no affinity floors, no arousal gate, no orientation walls - any adult can engage any adult, NPC or player, freely. Everything informational keeps running (scenes, tiers, physics, gaze, drugs, fertility), NPCs keep in-character agency to refuse, relationships keep evolving in the background, and prostitutes still require payment. The notice is injected every turn so the model knows the rules of this world.</p>
                    </div>
                    <span class="section-save-btn" onclick="saveSettings(); savePromptSettings();">Save</span>
                </div>
                <div class="form-group" style="margin-top: 8px;">
                    <label>Open Mode Notice (model-facing)</label>
                    <textarea id="promptOpenModeNotice" class="auto-resize" style="min-height: 48px; width: 100%; resize: none; overflow: hidden;"></textarea>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: SCENE VOICE & PLAYER GATING -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('sceneVoice')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> Scene Voice &amp; Player Gating</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="sceneVoiceToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="sceneVoiceContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">

                <!-- TTS Modifier for Speech -->
                <h3 style="margin: 5px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">TTS Modifier for Speech</h3>
                <div class="settings-slider-group">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div class="settings-checkbox-group" style="margin: 0; padding: 0; border: none; min-width: 280px;">
                            <label for="xttsModifyLevel1">
                                <input type="checkbox" id="xttsModifyLevel1" name="XTTS_MODIFY_LEVEL1">
                                <span>TTS Modifier - Level 1 (Idle/Foreplay)</span>
                            </label>
                        </div>
                        <div class="slider-container" style="flex: 1;">
                            <input type="range" id="xttsSpeedLevel1" name="XTTS_SPEED_LEVEL1" min="0.1" max="1.2" step="0.05" value="0.8">
                            <span class="slider-value" id="xttsSpeedLevel1Value">0.8x</span>
                        </div>
                    </div>
                    <p class="legend">NPCs will talk at the selected speed when in idle/foreplay scenes. Lower = slower, sexier. Default: 0.8x</p>
                </div>

                <!-- Action Voice / Random Moans -->
                <h3 style="margin: 20px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Action Voice &amp; Random Moans</h3>
                <div class="settings-slider-group">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div class="settings-checkbox-group" style="margin: 0; padding: 0; border: none; min-width: 280px;">
                            <label for="xttsModifyLevel2">
                                <input type="checkbox" id="xttsModifyLevel2" name="XTTS_MODIFY_LEVEL2">
                                <span>Enable TTS Modifier - Level 2 (Action)</span>
                            </label>
                        </div>
                        <div class="slider-container" style="flex: 1;">
                            <input type="range" id="xttsSpeedLevel2" name="XTTS_SPEED_LEVEL2" min="0.1" max="1.2" step="0.05" value="0.7">
                            <span class="slider-value" id="xttsSpeedLevel2Value">0.7x</span>
                        </div>
                    </div>
                    <p class="legend">This is the Level 2 control required by Random Moans. It applies the selected action-scene voice speed and enables moan/gasp insertion below. Lower speed = slower and breathier. Default: 0.7x.</p>
                </div>

                <div class="settings-slider-group">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="margin: 0; padding: 0; min-width: 280px; color: #B8A8C8;">Cartesia climax voice speed</div>
                        <div class="slider-container" style="flex: 1;">
                            <input type="range" id="cartesiaSpeedClimax" name="CARTESIA_SPEED_CLIMAX" min="0.6" max="1.2" step="0.05" value="0.6">
                            <span class="slider-value" id="cartesiaSpeedClimaxValue">0.6x</span>
                        </div>
                    </div>
                    <p class="legend">With a Cartesia voice, the TTS Modifier levels above also set the voice speed (Cartesia takes 0.6x to 1.5x; older Cartesia models round to the nearest of slowest, slow, normal, fast, fastest). This one is the speed on an orgasm beat, and follows the Level 2 switch. Default: 0.6x.</p>
                </div>

                <div style="display: flex; align-items: center; gap: 15px; margin: 10px 0 5px 0;">
                    <div class="settings-checkbox-group" style="margin: 0; padding: 0; border: none;">
                        <label for="enableRandomMoans">
                            <input type="checkbox" id="enableRandomMoans" name="ENABLE_RANDOM_MOANS" checked>
                            <span>Inject Random Moans</span>
                        </label>
                    </div>
                    <select id="moansAffinityThreshold" name="MOANS_AFFINITY_THRESHOLD" style="width: 180px; padding: 8px; background: #1E1A2E; border: 2px solid #FDF5D0; border-radius: 4px; color: #B8A8C8; cursor: pointer; animation: goldNeonPulse 3s ease-in-out infinite alternate;">
                        <option value="-100">Hostile (-100)</option>
                        <option value="-90">Hateful (-90)</option>
                        <option value="-75">Resentful (-75)</option>
                        <option value="-55">Cold (-55)</option>
                        <option value="-30">Wary (-30)</option>
                        <option value="-5">Neutral (-5)</option>
                        <option value="6" selected>Acquaintance (6)</option>
                        <option value="31">Friendly (31)</option>
                        <option value="56">Fond (56)</option>
                        <option value="76">Devoted (76)</option>
                        <option value="91">Bonded (91)</option>
                    </select>
                </div>
                <p class="legend" style="margin: 0;">Random moans/gasps are inserted only when Level 2 above is enabled. The affinity dropdown sets the minimum relationship level - NPCs below this won't moan (e.g., a hostile victim won't moan with pleasure).</p>
                <div class="form-group" style="margin: 5px 0 15px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Moan Sounds (one per line)</label>
                    <textarea id="randomMoanSounds" class="auto-resize" style="min-height: 80px; width: 100%; resize: none; overflow: hidden; background: #252233; border: 1px solid #3A3545; border-radius: 5px; padding: 10px; color: #B8A8D0;"> ... oh ...
 ... ah ...
 ... mmm ...
 ... ooh ...
 ... yes ... </textarea>
                </div>

                <!-- NPC Sex Cooldown -->
                <h3 style="margin: 20px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Cooldowns &amp; Gating</h3>
                <div class="settings-slider-group">
                    <span class="slider-title">NPC Sex Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="npcSexCooldown" name="NPC_SEX_COOLDOWN_HOURS" min="0" max="24" step="1" value="9">
                        <span class="slider-value" id="npcSexCooldownValue">9 hours</span>
                    </div>
                    <p class="legend">Time (in game hours) before an NPC can engage in another sex scene. Set to 0 to disable cooldown entirely. Default: 9 hours.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Scene-Call Minimum Affinity</span>
                    <div class="slider-container">
                        <input type="range" id="sceneCallMinAffinity" name="NSFW_SCENE_CALL_MIN_AFFINITY" min="0" max="100" step="1" value="56" oninput="var v=parseInt(this.value); var t=v>=91?'Bonded':(v>=76?'Devoted':(v>=56?'Fond':(v>=31?'Friendly':(v>=6?'Acquaintance':'Neutral')))); document.getElementById('sceneCallMinAffinityValue').textContent = v + ' (' + t + ')';">
                        <span class="slider-value" id="sceneCallMinAffinityValue">56 (Fond)</span>
                    </div>
                    <p class="legend">Minimum relationship affinity before an NPC may, on her own, CALL or initiate an OStim/SexLab sex scene with you. Default 56 = Fond (the romance floor). Raise it to require a deeper bond (76 = Devoted, 91 = Bonded) or lower it to loosen the gate. This governs only the NPC initiating - explicit consent (AcceptSex), slaves, paid prostitutes, and skooma-bargains bypass this floor as before.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="instantCrushOnAffection">
                        <input type="checkbox" id="instantCrushOnAffection" name="INSTANT_CRUSH_ON_AFFECTION">
                        <span>Affection Flips Relationship to Crush</span>
                    </label>
                    <p class="legend">When an NPC chooses affection (hug, kiss, hand-holding) or agrees to intimacy with you, their relationship type flips to crush automatically - actions speak louder than words. Requires Fond+ affinity; slaves and prostitutes are excluded; locked relationship cards are never touched. Default: on.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Affair Minimum Affinity (Married NPCs)</span>
                    <div class="slider-container">
                        <input type="range" id="affairMinAffinity" name="NSFW_AFFAIR_MIN_AFFINITY" min="0" max="100" step="1" value="56" oninput="var v=parseInt(this.value);var t=v>=91?'Bonded':(v>=76?'Devoted':(v>=56?'Fond':(v>=31?'Friendly':(v>=6?'Acquaintance':'Neutral'))));document.getElementById('affairMinAffinityValue').textContent = v + ' (' + t + ')';">
                        <span class="slider-value" id="affairMinAffinityValue">56 (Fond)</span>
                    </div>
                    <p class="legend">Affinity an NPC married to someone else needs before an affair with you is even possible. The relationship type must still be a checked sex-eligible one. Default 56 = Fond; raise to 76 (Devoted) for stricter affair rules.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="nsfwGazeEnabled">
                        <input type="checkbox" id="nsfwGazeEnabled" name="NSFW_GAZE_ENABLED" checked>
                        <span>Gaze Reactions (staring)</span>
                    </label>
                    <p class="legend">When the plugin detects you staring at an NPC for a while, they react in character based on your relationship - a stranger gets uncomfortable, a lover finds it charged. Staring at the chest/butt/crotch (male player, female NPC) gets a lewd reaction; eyes-gazing works for any pairing. Requires the current SHARMAT AIAgent.dll. Reaction wording is on the Prompts tab (Gaze Reactions).</p>
                    <div style="margin-top: 6px;">
                        <label style="color: #9988BB; font-size: 12px;">Reaction cooldown (seconds)</label>
                        <input type="number" id="nsfwGazeCooldown" name="NSFW_GAZE_COOLDOWN_SECONDS" min="0" max="600" step="5" value="25" style="width: 80px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Minimum seconds between gaze reactions from the same NPC. Default: 25.</p>
                    </div>
                    <div class="settings-slider-group" style="margin-top: 10px;">
                        <span class="slider-title">Exact-Match Repeat Cooldown</span>
                        <div class="slider-container">
                            <input type="range" id="nsfwGazeExactMatchCooldown" name="NSFW_GAZE_EXACT_MATCH_COOLDOWN_SECONDS" min="0" max="600" step="5" value="120" oninput="document.getElementById('nsfwGazeExactMatchCooldownValue').textContent = this.value === '0' ? 'Off' : this.value + ' sec';">
                            <span class="slider-value" id="nsfwGazeExactMatchCooldownValue">120 sec</span>
                        </div>
                        <p class="legend">Additional safeguard for the exact same NPC and gaze region (for example, Jaryra + tits). Other regions remain available after the normal reaction cooldown. Set to 0 to disable this safeguard. Default: 120 seconds.</p>
                    </div>
                </div>

                <div class="settings-checkbox-group">
                    <label for="nsfwAffectionLegacyAnims">
                        <input type="checkbox" id="nsfwAffectionLegacyAnims" name="NSFW_AFFECTION_LEGACY_ANIMS">
                        <span>Use Legacy Hug/Kiss Animations</span>
                    </label>
                    <p class="legend">Off (default): hugs and kisses use persistent OStim scenes with proper actor alignment (better in VR, and they can flow into escalation). On: hugs and kisses use the old lightweight paired-idle animations instead. Hand-holding always uses OStim (it has no legacy animation). Requires the current SHARMAT mod scripts.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="nsfwCombatBlockEnabled">
                        <input type="checkbox" id="nsfwCombatBlockEnabled" name="NSFW_COMBAT_BLOCK_ENABLED" checked>
                        <span>Combat Blocks Affection &amp; Scene Calls</span>
                    </label>
                    <p class="legend">While there is live conflict around you (anyone nearby in combat or hostile), NPCs cannot start affection (hug, kiss, hand-holding) or call sex scenes with you - no more dying mid hand-hold while bandits beat on you. Player-directed only: NPC-to-NPC scenes are unaffected, and exit tools stay available. Default: on.</p>
                    <div style="margin-top: 6px;">
                        <label style="color: #9988BB; font-size: 12px;">Conflict window (seconds)</label>
                        <input type="number" id="nsfwCombatBlockWindow" name="NSFW_COMBAT_BLOCK_WINDOW_SECONDS" min="5" max="300" step="5" value="45" style="width: 80px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">How recent the combat report must be to count as live. Default: 45.</p>
                    </div>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Player Scene-Call Cooldown (Global)</span>
                    <div class="slider-container">
                        <input type="range" id="playerSceneCallCooldown" name="NSFW_PLAYER_SCENE_CALL_COOLDOWN_SECONDS" min="0" max="600" step="5" value="30" oninput="document.getElementById('playerSceneCallCooldownValue').textContent = (parseInt(this.value)===0 ? 'Off' : this.value + ' sec');">
                        <span class="slider-value" id="playerSceneCallCooldownValue">30 sec</span>
                    </div>
                    <p class="legend">One shared gate across ALL NPCs: the minimum time between any NPC initiating a sex scene with you, so several NPCs can't bombard you with scene-calls back to back. Counts from your last scene's activity/end. Affection (kiss/hug/hold-hands) and Accept/Refuse are never blocked by this. Set to 0 to disable. Default: 30 sec.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="nsfwAllowPaceControl">
                        <input type="checkbox" id="nsfwAllowPaceControl" name="NSFW_ALLOW_PACE_CONTROL" checked>
                        <span>Allow Pace Control</span>
                    </label>
                    <p class="legend">When ON, an NPC in a scene may speed up or slow down the OStim tempo on her own (QuickenPace / SlowPace). Turn OFF if you don't want the model changing scene speed. ON by default.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="nsfwAllowMidsceneSteering">
                        <input type="checkbox" id="nsfwAllowMidsceneSteering" name="NSFW_ALLOW_MIDSCENE_STEERING" checked>
                        <span>Allow Mid-Scene Position Steering</span>
                    </label>
                    <p class="legend">When ON, an NPC in a scene may switch position/act mid-scene (e.g. to oral, anal, a new position) as her own choice - this is model-driven and NOT tied to kink selection. Turn OFF to keep the starting scene throughout. ON by default.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="nsfwAffectionCooldownEnabled">
                        <input type="checkbox" id="nsfwAffectionCooldownEnabled" name="NSFW_AFFECTION_COOLDOWN_ENABLED" checked>
                        <span>Throttle Repeated Affection</span>
                    </label>
                    <p class="legend">When ON, an NPC can't spam hold-hands / hug / kiss back to back - after one affection act, the rest are withheld for about a minute so she doesn't keep re-triggering little affection scenes. Sex acts are unaffected. ON by default.</p>
                </div>

                <div class="settings-checkbox-group">
                    <p class="legend">Consent is model-driven: the NPC decides from their relationship tier prompt, personality, and scene context. There is no hard accept gate. Sex still requires the NPC be romanticized (Fond+ by default, adjustable above) - that relationship floor is the gate. The model may use AcceptSex to opt in, and RefuseSex to say no / leave (refusal sticks until the scene ends). Prostitutes (payment) and slaves (forced) keep their own handling.</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: NPC-TO-NPC SCENE CADENCE -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('npcSceneRuntime')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> NPC-to-NPC Scene Cadence</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="npcSceneRuntimeToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="npcSceneRuntimeContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <p class="legend" style="margin-bottom: 15px;">Background NPC-only scene routing, call-and-response pacing, and distance priority. Player scenes use the player-scene settings below.</p>

                <div class="settings-checkbox-group">
                    <label for="nsfwAllowNpcJoinScenes">
                        <input type="checkbox" id="nsfwAllowNpcJoinScenes" name="NSFW_ALLOW_NPC_JOIN_SCENES" checked>
                        <span>Allow NPCs to Start/Join Scenes</span>
                    </label>
                    <p class="legend">When ON, an NPC already in an OStim/SexLab scene may call StartSex/StartThreesome to pull another present actor - including YOU - into her scene (she can only target actors who are actually nearby). This is how an NPC starts a threesome or invites the player in. When OFF, in-scene NPCs react in dialogue only and never reach for the scene-join action. ON by default.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="npcSceneLlmEnabled">
                        <input type="checkbox" id="npcSceneLlmEnabled" name="NPC_SCENE_LLM_ENABLED" checked>
                        <span>Enable Live NPC-to-NPC Scene Dialogue</span>
                    </label>
                    <p class="legend">When enabled, NPC-only OStim/SexLab scene updates may trigger model dialogue for rotating participants. When disabled, SHARMAT only records context/state for NPC scenes and suppresses live NPC scene chatter.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">NPC Scene Context Throttle</span>
                    <div class="slider-container">
                        <input type="range" id="npcSceneContextThrottle" name="NPC_SCENE_CONTEXT_THROTTLE_SECONDS" min="1" max="60" step="1" value="6">
                        <span class="slider-value" id="npcSceneContextThrottleValue">6 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between context-only NPC scene updates for the same thread when live NPC scene dialogue is disabled. Default: 6 seconds.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">NPC Scene Global Speech Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="npcSceneGlobalCooldown" name="NPC_SCENE_GLOBAL_COOLDOWN_SECONDS" min="5" max="90" step="5" value="25">
                        <span class="slider-value" id="npcSceneGlobalCooldownValue">25 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between ordinary NPC-only scene lines across all active background scenes. This is the gap between one NPC line and the partner's reply. Orgasms bypass this. Default: 25 seconds.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">NPC Scene Thread Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="npcSceneThreadCooldown" name="NPC_SCENE_THREAD_COOLDOWN_SECONDS" min="10" max="180" step="5" value="60">
                        <span class="slider-value" id="npcSceneThreadCooldownValue">60 sec</span>
                    </div>
                    <p class="legend">Pair cooldown after an NPC-only scene thread completes a call-and-response turn. The partner reply is allowed before this cooldown starts. Default: 60 seconds.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">NPC Scene Actor Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="npcSceneActorCooldown" name="NPC_SCENE_ACTOR_COOLDOWN_SECONDS" min="10" max="240" step="5" value="75">
                        <span class="slider-value" id="npcSceneActorCooldownValue">75 sec</span>
                    </div>
                    <p class="legend">Minimum seconds before the same NPC can start another ordinary NPC-only scene line. The immediate partner reply is not blocked by this. Default: 75 seconds.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">NPC Scene Stale Timeout (safety net)</span>
                    <div class="slider-container">
                        <input type="range" id="npcSceneStaleSeconds" name="NPC_SCENE_STALE_SECONDS" min="60" max="600" step="30" value="330">
                        <span class="slider-value" id="npcSceneStaleSecondsValue">330 sec</span>
                    </div>
                    <p class="legend">Fallback only. OStim ends NPC scenes itself (its own timer, default ~300s, or on orgasm) and fires the scene-end event that clears state. This timeout is a safety net for scenes that were abandoned without a clean end (e.g. actor left the cell, save/reload). Keep it ABOVE OStim's scene timer (~300s) so it never clears a scene that is still running. Minimum 60 seconds. Default: 330 seconds.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">NPC Scene Distance Priority Margin</span>
                    <div class="slider-container">
                        <input type="range" id="npcSceneDistancePriorityMargin" name="NPC_SCENE_DISTANCE_PRIORITY_MARGIN" min="0" max="512" step="16" value="96">
                        <span class="slider-value" id="npcSceneDistancePriorityMarginValue">96 units</span>
                    </div>
                    <p class="legend">When player distance metadata is available, a farther NPC scene yields to a closer eligible scene by this many Skyrim units. Default: 96 units.</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: PLAYER SCENE ROUTING & RESPONSE -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('playerSceneRuntime')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> Player Scene Routing &amp; Response</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="playerSceneRuntimeToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="playerSceneRuntimeContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <p class="legend" style="margin-bottom: 15px;">Player-involved scene routing, legacy scene event policy, response caps, and player-scene cooldowns.</p>

                <div class="settings-checkbox-group">
                    <label for="detectOStimScenes">
                        <input type="checkbox" id="detectOStimScenes" name="DETECT_OSTIM_SCENES" checked>
                        <span>Detect OStim Scenes</span>
                    </label>
                    <p class="legend">When checked, SHARMAT describes OStim scenes (player, group, and NPC-to-NPC). Uncheck to ignore OStim events so another plugin can own that framework.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="detectSexLabScenes">
                        <input type="checkbox" id="detectSexLabScenes" name="DETECT_SEXLAB_SCENES" checked>
                        <span>Detect SexLab Scenes</span>
                    </label>
                    <p class="legend">When checked, SHARMAT describes SexLab scenes. Uncheck to let another overlay (for example SexLab Defeat) own victim scenes. The game also reads <code>Data/SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json</code> (<code>ostim</code>/<code>sexlab</code> as 1 or 0) so hooks are not registered; reload a save after changing that file. These checkboxes do not write the JSON.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="blockRechatInScene">
                        <input type="checkbox" id="blockRechatInScene" name="BLOCK_RECHAT_IN_SCENE" checked>
                        <span>Throttle Rechat During Scenes</span>
                    </label>
                    <p class="legend">When enabled, rechat and narration are rate-limited (not blocked) while a scene is active &mdash; one line per "NPC Scene Global Speech Cooldown" interval &mdash; so scene partners and nearby witnesses can still comment on what they see without flooding the server. Throttling lifts the instant the scene ends.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="sceneSpeakOnSceneChange">
                        <input type="checkbox" id="sceneSpeakOnSceneChange" name="NSFW_SCENE_SPEAK_ON_SCENE_CHANGE" checked>
                        <span>Respond to Scene/Position Changes</span>
                    </label>
                    <p class="legend">When checked, your partner comments every time the scene position or animation changes. Uncheck if the AI feels too chatty during scenes: position changes then update the scene state silently, while scene starts (the consent decision turn), orgasm reactions, and the Player Scene Chatter Cadence below keep working normally.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="vocalSilence">
                        <input type="checkbox" id="vocalSilence" name="NSFW_VOCAL_SILENCE" checked>
                        <span>Let Quiet NPCs Stay Quiet</span>
                    </label>
                    <p class="legend">When checked, a speak style with a "chance to stay quiet" (and, when RelDyn is installed, each NPC's own vocal style: a guarded NPC says little, a bold one a lot) leaves some position-change beats unspoken. The game's own moans and breaths still play, the accept/refuse turn and orgasms always speak, and nobody stays silent for long. Uncheck to make every NPC answer every beat.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="sceneSpeakOnOrgasm">
                        <input type="checkbox" id="sceneSpeakOnOrgasm" name="NSFW_SCENE_SPEAK_ON_ORGASM" checked>
                        <span>Respond to Orgasms</span>
                    </label>
                    <p class="legend">When checked, NPCs verbally react to orgasms &mdash; their own, yours, and other participants'. Uncheck for no spoken climax lines: orgasm tracking, arousal, and post-scene pillow talk still process normally, so nothing downstream breaks.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="groupSceneParticipantDialogue">
                        <input type="checkbox" id="groupSceneParticipantDialogue" name="GROUP_SCENE_PARTICIPANT_DIALOGUE" checked>
                        <span>Group-Scene Participant Dialogue</span>
                    </label>
                    <p class="legend">In a group scene, lets the non-primary partners take their own scene-aware turns (a RANDOM participant per tick, throttled to the Group-Scene Chatter Tick below) instead of only the primary partner speaking. Generates extra NPC turns during group scenes; turn it off if you want only the primary partner to talk.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Group-Scene Chatter Tick</span>
                    <div class="slider-container">
                        <input type="range" id="groupSceneTickSeconds" name="GROUP_SCENE_TICK_SECONDS" min="0" max="120" step="5" value="0" oninput="document.getElementById('groupSceneTickSecondsValue').textContent = (this.value == 0) ? 'Global Speech Cooldown' : this.value + ' sec';">
                        <span class="slider-value" id="groupSceneTickSecondsValue">Global Speech Cooldown</span>
                    </div>
                    <p class="legend">How often a random extra participant chimes in during group scenes (threesomes/orgies) when Group-Scene Participant Dialogue is on. 0 = follow the NPC Scene Global Speech Cooldown (default 25s). Raise it for quieter orgies where partners only occasionally speak up.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Legacy Scene-Speak Policy</span>
                    <select id="legacySceneSpeakPolicy" name="LEGACY_SCENE_SPEAK_POLICY" style="width: 100%; padding: 8px; background: #1E1A2E; border: 2px solid #FDF5D0; border-radius: 4px; color: #B8A8C8; cursor: pointer; animation: goldNeonPulse 3s ease-in-out infinite alternate;">
                        <option value="authoritative" selected>Authoritative scene gate</option>
                        <option value="block_all">Block all legacy chatnf_sl prompts</option>
                        <option value="allow">Allow legacy chatnf_sl prompts</option>
                    </select>
                    <p class="legend">Controls legacy OStim/SexLab scene-speak events. Authoritative scene gate lets ext_nsfw_sexcene own standing/consent prompting and blocks duplicate chatnf_sl chatter. Block all prevents chatnf_sl-style events from reaching the model at all.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="nsfwEventAuditLog">
                        <input type="checkbox" id="nsfwEventAuditLog" name="NSFW_EVENT_AUDIT_LOG" checked>
                        <span>Log NSFW Event Routing</span>
                    </label>
                    <p class="legend">Writes every NSFW scene event received by the server to the PHP log with event type, profile, policy, and trimmed payload.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Scene Consent Carryover</span>
                    <div class="slider-container">
                        <input type="range" id="sceneConsentCarryover" name="SCENE_CONSENT_CARRYOVER_SECONDS" min="0" max="3600" step="60" value="1800">
                        <span class="slider-value" id="sceneConsentCarryoverValue">1800 seconds</span>
                    </div>
                    <p class="legend">How long a model AcceptSex decision from a standing/consent beat can carry into the matching active OStim scene. Set to 0 to require a fresh accept every new scene. Default: 1800 seconds.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Scene-Active Window for Rechat Throttle</span>
                    <div class="slider-container">
                        <input type="range" id="blockRechatTimeout" name="BLOCK_RECHAT_TIMEOUT" min="30" max="600" step="30" value="300">
                        <span class="slider-value" id="blockRechatTimeoutValue">300 seconds</span>
                    </div>
                    <p class="legend">How long (seconds) after the last scene event a scene still counts as "active" for rechat throttling. Within this window rechat is rate-limited to the Global Speech Cooldown; a clean scene-end lifts it immediately regardless. Default 300s.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Player Scene Chatter Cadence</span>
                    <div class="slider-container">
                        <input type="range" id="playerSceneRechatCadence" name="PLAYER_SCENE_RECHAT_CADENCE_SECONDS" min="0" max="120" step="5" value="0" oninput="document.getElementById('playerSceneRechatCadenceValue').textContent = (this.value == 0) ? 'OFF (hard block)' : this.value + ' sec';">
                        <span class="slider-value" id="playerSceneRechatCadenceValue">OFF (hard block)</span>
                    </div>
                    <p class="legend">Proactive mid-scene re-prompting for YOUR scenes. OFF (default) keeps ambient rechat fully blocked while you are in a scene. Set an interval and one rechat per interval passes through carrying the full scene cue, so your partner keeps generating scene dialogue between animation changes and touches - without out-of-place greeting lines.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Prostitute Payment Window</span>
                    <div class="slider-container">
                        <input type="range" id="prostitutePaymentWindow" name="PROSTITUTE_PAYMENT_WINDOW_MINUTES" min="0" max="120" step="5" value="20">
                        <span class="slider-value" id="prostitutePaymentWindowValue">20 minutes</span>
                    </div>
                    <p class="legend">How long a confirmed prostitute payment stays valid before she requires paying again. The payment is also consumed the moment the player orgasms (service rendered), whichever comes first. Set to 0 to make payment last only until the player orgasms (no time limit). Default 20 minutes.</p>
                </div>

                <!-- Scene Event Cooldowns -->
                <h3 style="margin: 20px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Scene Event Cooldowns</h3>
                <p class="legend" style="margin-bottom: 15px;">Control how often player-scene position changes and chatnf_sl events can trigger another spoken line. Scene state still updates on every change; only model/TTS speech is paced. NPC-only scene cadence is controlled by the NPC Scene settings above.</p>

                <div class="settings-slider-group">
                    <span class="slider-title">Sex Scene Dialogue Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="cooldownSexScene" name="COOLDOWN_SEX_SCENE" min="0" max="60" step="5" value="15">
                        <span class="slider-value" id="cooldownSexSceneValue">15 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between player-scene chatnf_sl events. Prevents spam during rapid animation changes. Default: 15 seconds.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Climax/Orgasm Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="cooldownClimax" name="COOLDOWN_CLIMAX" min="0" max="120" step="5" value="30">
                        <span class="slider-value" id="cooldownClimaxValue">30 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between orgasm/climax events. Prevents multiple orgasm responses in quick succession. Default: 30 seconds.</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: VR PHYSICAL CONTACT -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('vrPhysicalContact')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> VR Physical Contact</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="vrPhysicalContactToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="vrPhysicalContactContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <div class="settings-checkbox-group">
                    <label for="nsfwVrTouchEnabled">
                        <input type="checkbox" id="nsfwVrTouchEnabled" name="NSFW_VR_TOUCH_ENABLED" checked>
                        <span>VR Touch Reactions (CBPC contact)</span>
                    </label>
                    <p class="legend">Master switch for touch reactions. NPCs react when your VR hands actually touch them (CBPC collision events from the plugin). The platform is now AUTO-DETECTED: on flatscreen/2D installs the game mod never registers touch events and the server drops any strays, regardless of this switch - no action needed. This switch remains the manual control for VR players (for example if your collision setup triggers through worn armor). Gaze reactions are separate and stay on.</p>
                </div>

                <h3 style="margin: 5px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">VR Touch &amp; Grab Cooldowns</h3>
                <p class="legend" style="margin-bottom: 15px;">Debounce for VR physical contact (HIGGS grab / CBPC touch / spank). Keep these SHORT - this only stops a single contact firing repeatedly, it is NOT a lockout. NPCs should stay aware of being touched.</p>

                <div class="settings-slider-group">
                    <span class="slider-title">Grab Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="physicsGrabCooldown" name="PHYSICS_GRAB_COOLDOWN" min="1" max="60" step="1" value="2" oninput="document.getElementById('physicsGrabCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="physicsGrabCooldownValue">2 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between intentional grabs before the NPC reacts again. Default: 2 sec.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Touch Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="physicsTouchCooldown" name="PHYSICS_TOUCH_COOLDOWN" min="1" max="120" step="1" value="2" oninput="document.getElementById('physicsTouchCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="physicsTouchCooldownValue">2 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between repeated touches on the same body part. Clear sensitive zones can still fire separately; approximate body-zone jitter uses the cooldown below. Default: 2 sec.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">In-Scene Touch Debounce</span>
                    <div class="slider-container">
                        <input type="range" id="physicsTouchSceneCooldown" name="PHYSICS_TOUCH_SCENE_COOLDOWN" min="10" max="600" step="10" value="120" oninput="document.getElementById('physicsTouchSceneCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="physicsTouchSceneCooldownValue">120 sec</span>
                    </div>
                    <p class="legend">While an OStim/SexLab scene is active, bodies are in constant contact, so touch is debounced to this much longer interval to avoid spamming the model. Out-of-scene touch uses the Touch Cooldown above. Default: 120 sec (2 min).</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Approximate Contact Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="physicsLowConfidenceCooldown" name="PHYSICS_LOW_CONFIDENCE_COOLDOWN" min="1" max="60" step="1" value="8" oninput="document.getElementById('physicsLowConfidenceCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="physicsLowConfidenceCooldownValue">8 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between noisy Body, Arm, Shoulder, Back, Belly, Leg, or Foot contact prompts for the same NPC. Sensitive zones and ass slaps can still break through. Default: 8 sec.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Sustained Breast Touch Threshold</span>
                    <div class="slider-container">
                        <input type="range" id="physicsSustainedBreastTouch" name="PHYSICS_SUSTAINED_BREAST_TOUCH_SECONDS" min="1" max="60" step="1" value="5" oninput="document.getElementById('physicsSustainedBreastTouchValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="physicsSustainedBreastTouchValue">5 sec</span>
                    </div>
                    <p class="legend">Continuous breast/chest touch on the same NPC before SHARMAT treats it as intentional fondling instead of an accidental brush. Default: 5 sec.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="physicsSpankEnabled">
                        <input type="checkbox" id="physicsSpankEnabled" name="PHYSICS_SPANK_ENABLED" checked>
                        <span>Enable Ass Slap Detection</span>
                    </label>
                    <p class="legend">When enabled, VR controller movement into an NPC's butt area can be classified as an ass slap only when the bridge reports a valid distance crossing and SHARMAT accepts its controller speed.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Ass Slap Speed Threshold</span>
                    <div class="slider-container">
                        <input type="range" id="physicsSpankMinSpeed" name="PHYSICS_SPANK_MIN_SPEED" min="80" max="380" step="5" value="100" oninput="document.getElementById('physicsSpankMinSpeedValue').textContent = this.value + ' speed';">
                        <span class="slider-value" id="physicsSpankMinSpeedValue">100 speed</span>
                    </div>
                    <p class="legend">How fast the controller must move before a butt contact counts as a slap. Higher values require a sharper hand motion. SHARMAT rejects missing speed data and enforces a safety floor of 80. Default: 100.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Ass Slap Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="physicsSpankCooldown" name="PHYSICS_SPANK_COOLDOWN" min="1" max="60" step="1" value="5" oninput="document.getElementById('physicsSpankCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="physicsSpankCooldownValue">5 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between ass slap reactions. Slaps during cooldown update contact context without forcing another line. Default: 5 sec.</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: TRACKING -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('trackingSettings')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> Tracking</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="trackingSettingsToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="trackingSettingsContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <div class="settings-checkbox-group">
                    <label for="trackFertilityInfo">
                        <input type="checkbox" id="trackFertilityInfo" name="TRACK_FERTILITY_INFO">
                        <span>Track Fertility Info</span>
                    </label>
                    <p class="legend">Track NPC fertility cycles</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: SLAVERY -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('slaverySettings')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> Slavery</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="slaverySettingsToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="slaverySettingsContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <p class="legend" style="margin-bottom: 15px;">Player-only mechanics for slave idles and hostile slave poisoning. These settings do not go to the language model as prompt text.</p>

                <h3 style="margin: 5px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Slave Idles</h3>
                <div class="settings-checkbox-group">
                    <label for="slaveryIdlesEnabled">
                        <input type="checkbox" id="slaveryIdlesEnabled" name="SLAVERY_IDLES_ENABLED" checked>
                        <span>Enable Slave Idle System</span>
                    </label>
                    <p class="legend">Master switch for slave ambient idles and semantic idle actions.</p>
                </div>

                <h3 style="margin: 18px 0 12px; color: #FDF5D0; font-size: 16px;">Slave Freedom</h3>
                <div class="settings-checkbox-group">
                    <label for="slaveryAllowAskFreedom">
                        <input type="checkbox" id="slaveryAllowAskFreedom" name="SLAVERY_ALLOW_ASK_FREEDOM">
                        <span>Allow Slaves to Ask for Freedom</span>
                    </label>
                    <p class="legend">When ON, a slave may use the AskForFreedom action to plead for release. It is only a REQUEST - you (the master) must explicitly agree in conversation before they can free themselves (AcceptFreedom clears their slave status). OFF by default.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="slaveryAmbientIdlesEnabled">
                        <input type="checkbox" id="slaveryAmbientIdlesEnabled" name="SLAVERY_AMBIENT_IDLES_ENABLED" checked>
                        <span>Enable Ambient Slave Idles</span>
                    </label>
                    <p class="legend">Allows SHARMAT to occasionally pick an affinity-appropriate idle when the slave is not in a higher-priority state.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="slaveryActionIdlesEnabled">
                        <input type="checkbox" id="slaveryActionIdlesEnabled" name="SLAVERY_ACTION_IDLES_ENABLED" checked>
                        <span>Enable Model-Callable Slave Idle Actions</span>
                    </label>
                    <p class="legend">Allows future semantic tools like WorshipMaster or BringMasterDrink to resolve through the alias map below.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="slaveryHomeServiceIdles">
                        <input type="checkbox" id="slaveryHomeServiceIdles" name="SLAVERY_HOME_SERVICE_IDLES" checked>
                        <span>Home-Only Service Idles</span>
                    </label>
                    <p class="legend">When enabled, service actions marked with <code>|home</code> only run inside player homes.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Ambient Idle Chance</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryIdleChance" name="SLAVERY_IDLE_CHANCE" min="0" max="100" step="1" value="12" oninput="document.getElementById('slaveryIdleChanceValue').textContent = this.value + '%';">
                        <span class="slider-value" id="slaveryIdleChanceValue">12%</span>
                    </div>
                    <p class="legend">Chance per eligible check to play an ambient slave idle. Default: 12%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Ambient Idle Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryIdleCooldown" name="SLAVERY_IDLE_COOLDOWN_SECONDS" min="15" max="600" step="15" value="120" oninput="document.getElementById('slaveryIdleCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="slaveryIdleCooldownValue">120 sec</span>
                    </div>
                    <p class="legend">Minimum real seconds between ambient slave idles for the same NPC. Default: 120 sec.</p>
                </div>

                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Slave Idle Alias Map</label>
                    <textarea id="slaveryIdleAliasMap" name="SLAVERY_IDLE_ALIAS_MAP" class="auto-resize" style="min-height: 175px; width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; resize: vertical;">WorshipMaster=IdlePray
AskMasterForFreedom=AskMasterForFreedom
BringMasterDrink=IdleMQ201HoldingDrinkTray|home
SweepMastersFloors=IdleLooseSweepingStart|home
WaitForMasterCommand=IdleSnapToAttention
PraiseMaster=IdlePray
ThinkAboutMaster=IdleStudy
WelcomeMaster=IdleSilentBow
SurrenderToMaster=IdleSurrender
ShowDisdainForMaster=IdleExamine
BraceForPain=IdleBracedPain
GraveStanding=IdleBowHeadAtGrave_01
BrokenGraveStanding=IdleBowHeadAtGrave_02</textarea>
                    <p class="legend">One semantic action per line: <code>ActionName=IdleEvent</code>. Add <code>|home</code> for player-home-only actions. Hex FormIDs are also supported by the runtime idle sender.</p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 12px;">
                    <div class="form-group"><label>Bonded Idle Actions</label><textarea id="slaveryTierIdleBonded" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">WorshipMaster
AskMasterForFreedom
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Devoted Idle Actions</label><textarea id="slaveryTierIdleDevoted" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">WaitForMasterCommand
PraiseMaster</textarea></div>
                    <div class="form-group"><label>Fond Idle Actions</label><textarea id="slaveryTierIdleFond" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">ThinkAboutMaster
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Friendly Idle Actions</label><textarea id="slaveryTierIdleFriendly" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">WelcomeMaster
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Acquainted Idle Actions</label><textarea id="slaveryTierIdleAcquaintance" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">WelcomeMaster
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Neutral Idle Actions</label><textarea id="slaveryTierIdleNeutral" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">GraveStanding
SurrenderToMaster
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Wary Idle Actions</label><textarea id="slaveryTierIdleWary" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">GraveStanding
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Cold Idle Actions</label><textarea id="slaveryTierIdleCold" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">BraceForPain
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Resentful Idle Actions</label><textarea id="slaveryTierIdleResentful" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">GraveStanding
ShowDisdainForMaster
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Hateful Idle Actions</label><textarea id="slaveryTierIdleHateful" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">BrokenGraveStanding
BringMasterDrink
SweepMastersFloors</textarea></div>
                    <div class="form-group"><label>Hostile Idle Actions</label><textarea id="slaveryTierIdleHostile" class="auto-resize" style="min-height: 80px; width: 100%; resize: vertical;">BrokenGraveStanding
BringMasterDrink
SweepMastersFloors</textarea></div>
                </div>
                <p class="legend" style="margin-top: 8px;">Runtime priority should skip slave idles during OStim/SexLab scenes, sap paralysis, unconsciousness, skooma/drug idles, drunk falls, combat, furniture, or other stronger animation states.</p>

                <h3 style="margin: 24px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Hostile Slave Poisoning</h3>
                <div class="settings-checkbox-group">
                    <label for="slaveryPoisonEnabled">
                        <input type="checkbox" id="slaveryPoisonEnabled" name="SLAVERY_POISON_ENABLED">
                        <span>Enable PoisonMasterFood</span>
                    </label>
                    <p class="legend">Allows qualifying hostile slaves to secretly arm a poison state on the player's next eligible consumable.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="slaveryPoisonSceneOnly">
                        <input type="checkbox" id="slaveryPoisonSceneOnly" name="SLAVERY_POISON_SCENE_ONLY" checked>
                        <span>Expose During Slave Sex Scenes Only</span>
                    </label>
                    <p class="legend">When enabled, PoisonMasterFood is only exposed while SHARMAT considers the slave inside an active scene.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="slaveryPoisonNotifyPlayer">
                        <input type="checkbox" id="slaveryPoisonNotifyPlayer" name="SLAVERY_POISON_NOTIFY_PLAYER" checked>
                        <span>Notify Player When Poison Fires</span>
                    </label>
                    <p class="legend">Shows a player notification such as "You have been poisoned." when the poison effect is applied.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Minimum Poison Tier</span>
                    <select id="slaveryPoisonMinTier" name="SLAVERY_POISON_MIN_TIER" style="width: 100%; padding: 8px; background: #1E1A2E; border: 2px solid #FDF5D0; border-radius: 4px; color: #B8A8C8; cursor: pointer; animation: goldNeonPulse 3s ease-in-out infinite alternate;">
                        <option value="resentful">Resentful</option>
                        <option value="hateful" selected>Hateful</option>
                        <option value="hostile">Hostile Only</option>
                    </select>
                    <p class="legend">Default: Hateful. The action should not be exposed above this relationship tier.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Hateful Tool Exposure Chance</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryPoisonHatefulChance" name="SLAVERY_POISON_HATEFUL_CHANCE" min="0" max="100" step="1" value="25" oninput="document.getElementById('slaveryPoisonHatefulChanceValue').textContent = this.value + '%';">
                        <span class="slider-value" id="slaveryPoisonHatefulChanceValue">25%</span>
                    </div>
                    <p class="legend">Chance that a hateful slave gets access to PoisonMasterFood on an eligible turn. Default: 25%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Hostile Tool Exposure Chance</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryPoisonHostileChance" name="SLAVERY_POISON_HOSTILE_CHANCE" min="0" max="100" step="1" value="100" oninput="document.getElementById('slaveryPoisonHostileChanceValue').textContent = this.value + '%';">
                        <span class="slider-value" id="slaveryPoisonHostileChanceValue">100%</span>
                    </div>
                    <p class="legend">Chance that a hostile slave gets access to PoisonMasterFood on an eligible turn. Default: 100%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Poison Success Chance</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryPoisonSuccessChance" name="SLAVERY_POISON_SUCCESS_CHANCE" min="0" max="100" step="1" value="65" oninput="document.getElementById('slaveryPoisonSuccessChanceValue').textContent = this.value + '%';">
                        <span class="slider-value" id="slaveryPoisonSuccessChanceValue">65%</span>
                    </div>
                    <p class="legend">Chance that an armed poison attempt succeeds when the player consumes eligible food/drink. Default: 65%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Poison Expiration</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryPoisonExpireHours" name="SLAVERY_POISON_EXPIRE_GAME_HOURS" min="1" max="168" step="1" value="24" oninput="document.getElementById('slaveryPoisonExpireHoursValue').textContent = this.value + ' game hours';">
                        <span class="slider-value" id="slaveryPoisonExpireHoursValue">24 game hours</span>
                    </div>
                    <p class="legend">How long the armed poison waits for the next eligible consumable before expiring. Default: 24 game hours.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Poison Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryPoisonCooldownHours" name="SLAVERY_POISON_COOLDOWN_GAME_HOURS" min="1" max="168" step="1" value="72" oninput="document.getElementById('slaveryPoisonCooldownHoursValue').textContent = this.value + ' game hours';">
                        <span class="slider-value" id="slaveryPoisonCooldownHoursValue">72 game hours</span>
                    </div>
                    <p class="legend">Per-slave cooldown after an attempt. Default: 72 game hours.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Poison Duration</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryPoisonDurationSeconds" name="SLAVERY_POISON_DURATION_SECONDS" min="15" max="600" step="15" value="120" oninput="document.getElementById('slaveryPoisonDurationSecondsValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="slaveryPoisonDurationSecondsValue">120 sec</span>
                    </div>
                    <p class="legend">Duration for the slow drain poison effect once Papyrus applies it. Default: 120 sec.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Poison Magnitude</span>
                    <div class="slider-container">
                        <input type="range" id="slaveryPoisonMagnitude" name="SLAVERY_POISON_MAGNITUDE" min="1" max="25" step="1" value="3" oninput="document.getElementById('slaveryPoisonMagnitudeValue').textContent = this.value;">
                        <span class="slider-value" id="slaveryPoisonMagnitudeValue">3</span>
                    </div>
                    <p class="legend">Magnitude passed to the Papyrus poison effect. Default: 3.</p>
                </div>
                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Poison Trigger Consumable Types</label>
                    <textarea id="slaveryPoisonConsumeTypes" name="SLAVERY_POISON_CONSUME_TYPES" class="auto-resize" style="min-height: 58px; width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; resize: vertical;">food
drink
potion
ingredient</textarea>
                    <p class="legend">Papyrus-side filter for which consumed item categories can trigger the armed poison state. Default: food, drink, potion, and ingredient.</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: AROUSAL -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('arousal')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> Arousal</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="arousalToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="arousalContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <div class="settings-checkbox-group">
                    <label for="enableSexDisposal">
                        <input type="checkbox" id="enableSexDisposal" name="ENABLE_SEX_DISPOSAL">
                        <span>Enable Arousal Gating</span>
                    </label>
                    <p class="legend">When enabled, intimate actions are progressively unlocked based on the NPC's arousal level. Arousal builds through flirty conversation (rate-limited by the gain cooldown below), affection, and intimate actions - and cools down with GAME time (sleep, waiting, and fast-travel all lower it, like sobering up). Thresholds below control what unlocks when; the scene-engage threshold lives on the Prompts tab (Arousal Gating Threshold). Relationship gates stay separate: the relationship decides IF she ever would (permission), arousal decides if she's in the mood right NOW (readiness) - both must pass. When disabled, all NSFW functions are available immediately.<br><br><strong style="color: #B8A8C8;">Pro tip:</strong> Combine this with the CHIM relationship system for deeply immersive romantic progression. As relationship tiers advance, receptiveness prompts make the AI warm to flirtation faster, so emotional connection and physical attraction develop together over time.</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="oslaSyncEnabled">
                        <input type="checkbox" id="oslaSyncEnabled" name="NSFW_OSLA_SYNC_ENABLED" checked>
                        <span>Publish SHARMAT Arousal to OSL/OStim</span>
                    </label>
                    <p class="legend">Publishes SHARMAT's AI-driven arousal on a 0-100 game scale. OSL Aroused mirrors the exact value when installed; OStim uses the same authoritative value as the NPC's scene excitement floor and progression tempo. One-way by design: SHARMAT is the authority, neither mod can write back, and NPC speech never pauses the excitement bar. Works with OStim even when OSL Aroused is not installed.</p>
                </div>

                <h3 style="margin: 20px 0 10px; color: #FDF5D0; font-size: 16px;">Arousal Tuning</h3>
                <p class="legend" style="margin-bottom: 12px;">All values only apply while Arousal Gating is enabled. Gains are points added per action; thresholds are the arousal needed to unlock that group of actions.</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px;">
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Decay per Game Hour</label>
                        <input type="number" id="arousalDecayPerHour" name="AROUSAL_DECAY_PER_GAME_HOUR" min="0" max="100" step="1" value="2" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Points lost per GAME hour. 0 = never cools down. Default: 2.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Conversation Gain Cooldown</label>
                        <input type="number" id="arousalGainCooldown" name="AROUSAL_GAIN_COOLDOWN_SECONDS" min="0" max="3600" step="5" value="60" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Seconds between conversation/mood arousal gains. 0 = every turn (old behavior). Default: 60.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Gain: Conversation/Mood</label>
                        <input type="number" id="arousalGainConversation" name="AROUSAL_GAIN_CONVERSATION" min="0" max="20" step="1" value="2" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Per flirty/relaxed beat (also the negative-mood drop). Default: 2.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Gain: Affection</label>
                        <input type="number" id="arousalGainAffection" name="AROUSAL_GAIN_AFFECTION" min="0" max="20" step="1" value="1" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Per hug/hand-hold; a kiss gives double. Default: 1.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Gain: Undressing</label>
                        <input type="number" id="arousalGainUndress" name="AROUSAL_GAIN_UNDRESS" min="0" max="50" step="1" value="6" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">When she removes her clothes. Default: 6.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Gain: Massage/Sensual</label>
                        <input type="number" id="arousalGainMassage" name="AROUSAL_GAIN_MASSAGE" min="0" max="50" step="1" value="5" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Massage and solo touch. Default: 5.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Gain: Sex Acts</label>
                        <input type="number" id="arousalGainSexact" name="AROUSAL_GAIN_SEXACT" min="0" max="50" step="1" value="15" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Per sex act she initiates or joins. Default: 15.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Gain: Paid Transaction</label>
                        <input type="number" id="arousalGainTransaction" name="AROUSAL_GAIN_TRANSACTION" min="0" max="50" step="1" value="10" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Prostitute accepting a transaction. Default: 10.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Gain: AcceptSex</label>
                        <input type="number" id="arousalGainAcceptsex" name="AROUSAL_GAIN_ACCEPTSEX" min="0" max="50" step="1" value="20" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">When she consents to a scene. Default: 20.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Drop: Refusal</label>
                        <input type="number" id="arousalDropRefusal" name="AROUSAL_DROP_REFUSAL" min="0" max="50" step="1" value="15" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Lost on a genuine RefuseSex. A "warm me up" pacing decline never drains. Default: 15.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Threshold: Undressing</label>
                        <input type="number" id="arousalThresholdUndress" name="AROUSAL_THRESHOLD_UNDRESS" min="0" max="100" step="1" value="5" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Arousal needed to unlock stripping. Default: 5.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Threshold: Foreplay</label>
                        <input type="number" id="arousalThresholdForeplay" name="AROUSAL_THRESHOLD_FOREPLAY" min="0" max="100" step="1" value="10" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Unlocks massage, handplay, solo. Default: 10.</p>
                    </div>
                    <div class="form-group">
                        <label style="color: #9988BB; font-size: 12px; display: block;">Threshold: Full Sex</label>
                        <input type="number" id="arousalThresholdSex" name="AROUSAL_THRESHOLD_SEX" min="0" max="100" step="1" value="20" style="width: 90px; padding: 6px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                        <p class="legend">Unlocks the full sex acts. Default: 20.</p>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: DRUNKENNESS -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('drunk')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> Drunkenness</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="drunkToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="drunkContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <div class="settings-checkbox-group">
                    <label for="trackDrunkStatus">
                        <input type="checkbox" id="trackDrunkStatus" name="TRACK_DRUNK_STATUS">
                        <span>Track Drunk Status</span>
                    </label>
                    <p class="legend">Track if NPCs are intoxicated</p>
                </div>

                <div class="settings-checkbox-group">
                    <label for="drunkRequireConsume">
                        <input type="checkbox" id="drunkRequireConsume" name="DRUNK_REQUIRE_CONSUME_ACTION">
                        <span>Real Drinks Only (Consume action)</span>
                    </label>
                    <p class="legend">Only the inventory-backed Consume action counts toward drunkenness - a real bottle is used and the drinking animation plays. Drink/Toast become social flavor that never intoxicates. Off = old behavior where Drink and Toast also count.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Drunkenness Duration</span>
                    <div class="slider-container">
                        <input type="range" id="drunkWindowHours" name="DRUNK_WINDOW_HOURS" min="1" max="24" step="1" value="12" oninput="document.getElementById('drunkWindowHoursValue').textContent = this.value + ' game hours';">
                        <span class="slider-value" id="drunkWindowHoursValue">12 game hours</span>
                    </div>
                    <p class="legend">How long (in GAME hours) drinks keep an NPC drunk before they wear off. Game time advances with sleep, waiting, and fast-travel, so those sober the NPC up. Default: 12 game hours.</p>
                </div>

                <!-- Whiskey Dick (a drunk mechanic - lives here under Drunkenness) -->
                <h3 style="margin: 20px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Whiskey Dick</h3>
                <div class="settings-checkbox-group">
                    <label for="whiskeyDickEnabled">
                        <input type="checkbox" id="whiskeyDickEnabled" name="WHISKEY_DICK_ENABLED">
                        <span>Enable Whiskey Dick</span>
                    </label>
                    <p class="legend"><strong style="color: #B8A8C8;">Male player only</strong> - a female player can't get whiskey dick, so it never fires for one. When a male player is drunk enough during a player scene, SHARMAT can interrupt the scene and send the partner a scene prompt. The reaction wording is on the Prompts tab; the impotence window length is the Whiskey Dick Duration slider below.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="whiskeyDickAutoEndScene">
                        <input type="checkbox" id="whiskeyDickAutoEndScene" name="WHISKEY_DICK_AUTO_END_SCENE" checked>
                        <span>Auto-End Scene On Trigger</span>
                    </label>
                    <p class="legend">Queues a SHARMAT stop command and shows the player "Sharmat- You have Whiskey Dick!"</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="whiskeyDickBullyingEnabled">
                        <input type="checkbox" id="whiskeyDickBullyingEnabled" name="WHISKEY_DICK_BULLYING_ENABLED">
                        <span>Allow Partner Teasing</span>
                    </label>
                    <p class="legend">Adds a sharper tone hint to the whiskey dick prompt. The actual wording is controlled on the Prompts tab.</p>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 10px 0;">
                    <div class="settings-slider-group">
                        <span class="slider-title">3 Drinks Chance</span>
                        <div class="slider-container">
                            <input type="range" id="whiskeyDickChance3" name="WHISKEY_DICK_CHANCE_3" min="0" max="100" step="1" value="25" oninput="document.getElementById('whiskeyDickChance3Value').textContent = this.value + '%';">
                            <span class="slider-value" id="whiskeyDickChance3Value">25%</span>
                        </div>
                    </div>
                    <div class="settings-slider-group">
                        <span class="slider-title">4 Drinks Chance</span>
                        <div class="slider-container">
                            <input type="range" id="whiskeyDickChance4" name="WHISKEY_DICK_CHANCE_4" min="0" max="100" step="1" value="50" oninput="document.getElementById('whiskeyDickChance4Value').textContent = this.value + '%';">
                            <span class="slider-value" id="whiskeyDickChance4Value">50%</span>
                        </div>
                    </div>
                    <div class="settings-slider-group">
                        <span class="slider-title">5 Drinks Chance</span>
                        <div class="slider-container">
                            <input type="range" id="whiskeyDickChance5" name="WHISKEY_DICK_CHANCE_5" min="0" max="100" step="1" value="75" oninput="document.getElementById('whiskeyDickChance5Value').textContent = this.value + '%';">
                            <span class="slider-value" id="whiskeyDickChance5Value">75%</span>
                        </div>
                    </div>
                    <div class="settings-slider-group">
                        <span class="slider-title">6+ Drinks Chance</span>
                        <div class="slider-container">
                            <input type="range" id="whiskeyDickChance6" name="WHISKEY_DICK_CHANCE_6" min="0" max="100" step="1" value="100" oninput="document.getElementById('whiskeyDickChance6Value').textContent = this.value + '%';">
                            <span class="slider-value" id="whiskeyDickChance6Value">100%</span>
                        </div>
                    </div>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Whiskey Dick Duration</span>
                    <div class="slider-container">
                        <input type="range" id="whiskeyDickDuration" name="NSFW_WHISKEY_DICK_DURATION_MINUTES" min="1" max="60" step="1" value="10" oninput="document.getElementById('whiskeyDickDurationValue').textContent = this.value + ' min';">
                        <span class="slider-value" id="whiskeyDickDurationValue">10 min</span>
                    </div>
                    <p class="legend">How long whiskey dick lasts once triggered. During this window the player can't perform - NPCs won't initiate sex with you - and, if SOS is installed, the schlong goes flaccid. Real minutes; default 10 (~3 in-game hours at default timescale).</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Whiskey Dick Drink Window</span>
                    <div class="slider-container">
                        <input type="range" id="whiskeyDickDrinkWindow" name="NSFW_PLAYER_DRUNK_WINDOW_MINUTES" min="1" max="30" step="1" value="5" oninput="document.getElementById('whiskeyDickDrinkWindowValue').textContent = this.value + ' min';">
                        <span class="slider-value" id="whiskeyDickDrinkWindowValue">5 min</span>
                    </div>
                    <p class="legend">How many <strong>real</strong> minutes your alcohol drinks stay counted toward whiskey dick. You must drink enough within this rolling window (3 drinks = the minimum threshold); older drinks age out. Default 5 min.</p>
                </div>

                <!-- Drunk Physics -->
                <h3 style="margin: 20px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Drunk Physics</h3>
                <p class="legend" style="margin-bottom: 15px;">Tuning for the physical drunk reactions (stumble / fall / pass-out). The drunk DURATION is the slider above.</p>

                <div class="settings-checkbox-group">
                    <label for="drunkAnimations">
                        <input type="checkbox" id="drunkAnimations" name="DRUNK_ANIMATIONS" checked>
                        <span>Enable Drunk Animations / Physics</span>
                    </label>
                    <p class="legend">Master switch for the OAR drunk walk plus the stumble/fall/pass-out reactions. Off = drunk is prompt and voice only.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="drunkRequireMovement">
                        <input type="checkbox" id="drunkRequireMovement" name="DRUNK_REQUIRE_MOVEMENT_FOR_STUMBLE" checked>
                        <span>Require Movement For Drunk Falls</span>
                    </label>
                    <p class="legend">When on, stage 6-8 falls require movement. Havok stumbles can fire in place. Stage 9 also has its own standing fall chance, and stage 10 blackout can still collapse in place.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Stumble Force</span>
                    <div class="slider-container">
                        <input type="range" id="drunkStumbleForce" name="DRUNK_STUMBLE_FORCE" min="1" max="15" step="0.5" value="6" oninput="document.getElementById('drunkStumbleForceValue').textContent = this.value;">
                        <span class="slider-value" id="drunkStumbleForceValue">6</span>
                    </div>
                    <p class="legend">How hard a drunken stumble shoves her. Higher = bigger lurch (too high will ragdoll her). Default: 6.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Physical Reaction Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="drunkStumbleCooldown" name="DRUNK_STUMBLE_COOLDOWN_SECONDS" min="0" max="120" step="5" value="30" oninput="document.getElementById('drunkStumbleCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="drunkStumbleCooldownValue">30 sec</span>
                    </div>
                    <p class="legend">Minimum real seconds between drunken stumbles/falls so she doesn't react to every line. Default: 30 sec.</p>
                </div>
                <h3 style="margin: 20px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Fall Chances</h3>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 6 Moving Fall Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkFallChanceS6" name="DRUNK_FALL_CHANCE_S6" min="0" max="100" step="1" value="5" oninput="document.getElementById('drunkFallChanceS6Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkFallChanceS6Value">5%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a stage 6 moving drunk fall. Default: 5%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 7 Moving Fall Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkFallChanceS7" name="DRUNK_FALL_CHANCE_S7" min="0" max="100" step="1" value="15" oninput="document.getElementById('drunkFallChanceS7Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkFallChanceS7Value">15%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a stage 7 moving drunk fall. Default: 15%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 8 Moving Fall Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkFallChanceS8" name="DRUNK_FALL_CHANCE_S8" min="0" max="100" step="1" value="35" oninput="document.getElementById('drunkFallChanceS8Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkFallChanceS8Value">35%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a stage 8 moving drunk fall. Default: 35%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 9 Moving Fall Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkFallChanceS9" name="DRUNK_FALL_CHANCE_S9" min="0" max="100" step="1" value="60" oninput="document.getElementById('drunkFallChanceS9Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkFallChanceS9Value">60%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a stage 9 fall while moving. Default: 60%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 9 Standing Fall Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkStandingFallChanceS9" name="DRUNK_STANDING_FALL_CHANCE_S9" min="0" max="100" step="1" value="20" oninput="document.getElementById('drunkStandingFallChanceS9Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkStandingFallChanceS9Value">20%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a stage 9 fall while standing still. Default: 20%.</p>
                </div>
                <h3 style="margin: 20px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Havok Stumble Chances</h3>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 6 Havok Stumble Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkStumbleChanceS6" name="DRUNK_STUMBLE_CHANCE_S6" min="0" max="100" step="1" value="15" oninput="document.getElementById('drunkStumbleChanceS6Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkStumbleChanceS6Value">15%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a Havok stumble. Movement is not required. Default: 15%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 7 Havok Stumble Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkStumbleChanceS7" name="DRUNK_STUMBLE_CHANCE_S7" min="0" max="100" step="1" value="25" oninput="document.getElementById('drunkStumbleChanceS7Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkStumbleChanceS7Value">25%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a Havok stumble. Movement is not required. Default: 25%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 8 Havok Stumble Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkStumbleChanceS8" name="DRUNK_STUMBLE_CHANCE_S8" min="0" max="100" step="1" value="35" oninput="document.getElementById('drunkStumbleChanceS8Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkStumbleChanceS8Value">35%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a Havok stumble. Movement is not required. Default: 35%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Stage 9 Havok Stumble Chance</span>
                    <div class="slider-container">
                        <input type="range" id="drunkStumbleChanceS9" name="DRUNK_STUMBLE_CHANCE_S9" min="0" max="100" step="1" value="50" oninput="document.getElementById('drunkStumbleChanceS9Value').textContent = this.value + '%';">
                        <span class="slider-value" id="drunkStumbleChanceS9Value">50%</span>
                    </div>
                    <p class="legend">Chance per eligible physics check for a Havok stumble after the stage 9 fall roll misses. Movement is not required. Default: 50%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Fall Delay After Drinking</span>
                    <div class="slider-container">
                        <input type="range" id="drunkFallAfterDrink" name="DRUNK_FALL_AFTER_DRINK_SECONDS" min="0" max="30" step="1" value="8" oninput="document.getElementById('drunkFallAfterDrinkValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="drunkFallAfterDrinkValue">8 sec</span>
                    </div>
                    <p class="legend">Grace period after a drink/toast animation before a stumble/fall can fire, so the drinking animation is not cut short. Default: 8 sec.</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: DRUGS -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('drugs')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> Drugs</h3>
                <div style="display: flex; gap: 8px; align-items: center;"><span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span><span id="drugsToggle" class="section-toggle-btn">Open</span></div>
            </div>
            <div id="drugsContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <p class="legend" style="margin-bottom: 15px;">Timing and effects for the drug system. The per-level prompts live on the Prompts tab; these are the numbers.</p>

                <div class="settings-checkbox-group">
                    <label for="drugsEnabled">
                        <input type="checkbox" id="drugsEnabled" name="DRUGS_ENABLED" checked>
                        <span>Enable Drug System</span>
                    </label>
                    <p class="legend">Master switch for skooma plus sleeping tree sap.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="drugAnimations">
                        <input type="checkbox" id="drugAnimations" name="DRUG_ANIMATIONS" checked>
                        <span>Enable Drug Animations</span>
                    </label>
                    <p class="legend">The skooma dance/strut/crazed idles plus the sap paralysis collapse. Off = drugs are prompt and voice only.</p>
                </div>
                <div class="settings-checkbox-group">
                    <label for="drugRequireConsumeAction">
                        <input type="checkbox" id="drugRequireConsumeAction" name="DRUG_REQUIRE_CONSUME_ACTION" checked>
                        <span>Require Real Consume Action For Hard Drugs</span>
                    </label>
                    <p class="legend">On = skooma and sleeping tree sap only count after the inventory-backed Consume action. Off allows legacy Drink/Consume matching.</p>
                </div>

                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Alcohol Match Terms</label>
                    <textarea id="alcoholMatchTerms" name="ALCOHOL_MATCH_TERMS" class="auto-resize" style="min-height: 58px; width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; resize: vertical;">wine
ale
mead
beer
brandy
spirits
liquor
grog
rum
whiskey
whisky
vodka
gin
absinthe
moonshine
rotgut
firebrand
honningbrew
black-briar
black briar
sujamma
flin
mazte</textarea>
                    <p class="legend">Saved backup/compatibility list. Runtime drunkness currently counts all Drink, Consume, and Toast actions except targets matching the skooma or sap terms below.</p>
                </div>
                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Skooma Match Terms</label>
                    <textarea id="skoomaMatchTerms" name="SKOOMA_MATCH_TERMS" class="auto-resize" style="min-height: 58px; width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; resize: vertical;">skooma
skuma
scuma
schuma
skoomah
skooma bottle
balmora blue
redwater
redwater skooma</textarea>
                    <p class="legend">Includes common speech-to-text misspellings. Add modded stimulant names here.</p>
                </div>
                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Sleeping Tree Sap Match Terms</label>
                    <textarea id="sapMatchTerms" name="SAP_MATCH_TERMS" class="auto-resize" style="min-height: 48px; width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; resize: vertical;">sleeping tree sap
sleeping-tree sap
tree sap
sleeping sap</textarea>
                    <p class="legend">One term per line. Add modded sedative/sap names here.</p>
                </div>

                <!-- ---- SKOOMA ---- -->
                <div style="border-bottom: 1px solid rgba(253, 245, 208, 0.3); margin: 20px 0; animation: settingsBorderPulse 3s ease-in-out infinite alternate;"></div>
                <h3 style="margin: 5px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Skooma</h3>

                <div class="settings-slider-group">
                    <span class="slider-title">Skooma L1 Wear-Off (honeymoon)</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaL1Wearoff" name="SKOOMA_L1_WEAROFF_HOURS" min="1" max="24" step="1" value="6" oninput="document.getElementById('skoomaL1WearoffValue').textContent = this.value + ' game hours';">
                        <span class="slider-value" id="skoomaL1WearoffValue">6 game hours</span>
                    </div>
                    <p class="legend">A single hit (Level 1) wears off cleanly to sober after this long with no follow-up. No addiction at L1. Default: 6.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Skooma L2 to L3 Decay (the treadmill)</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaL2Decay" name="SKOOMA_L2_DECAY_HOURS" min="1" max="24" step="1" value="3" oninput="document.getElementById('skoomaL2DecayValue').textContent = this.value + ' game hours';">
                        <span class="slider-value" id="skoomaL2DecayValue">3 game hours</span>
                    </div>
                    <p class="legend">How long she holds Level 2 before crashing to Level 3; she has to keep drinking to stay at L2. Default: 3.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Skooma L3 Detox</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaL3Detox" name="SKOOMA_L3_DETOX_HOURS" min="1" max="72" step="1" value="24" oninput="document.getElementById('skoomaL3DetoxValue').textContent = this.value + ' game hours';">
                        <span class="slider-value" id="skoomaL3DetoxValue">24 game hours</span>
                    </div>
                    <p class="legend">How long the Level 3 crash lasts before she rides it out to sober (if she does not re-dose back to L2). Default: 24.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Skooma Voice Speed - Level 1</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaTempo1" name="SKOOMA_TTS_TEMPO_1" min="1" max="1.6" step="0.05" value="1.1" oninput="document.getElementById('skoomaTempo1Value').textContent = this.value + 'x';">
                        <span class="slider-value" id="skoomaTempo1Value">1.1x</span>
                    </div>
                    <p class="legend">Voice speed-up at skooma Level 1 (stimulant). 1.0 = normal. Default: 1.10x.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Skooma Voice Speed - Level 2</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaTempo2" name="SKOOMA_TTS_TEMPO_2" min="1" max="1.6" step="0.05" value="1.2" oninput="document.getElementById('skoomaTempo2Value').textContent = this.value + 'x';">
                        <span class="slider-value" id="skoomaTempo2Value">1.2x</span>
                    </div>
                    <p class="legend">Voice speed-up at skooma Level 2 (peak). Default: 1.20x.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Skooma Voice Speed - Level 3 Withdrawal</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaTempo3" name="SKOOMA_TTS_TEMPO_3" min="0.7" max="1.6" step="0.05" value="1.0" oninput="document.getElementById('skoomaTempo3Value').textContent = this.value + 'x';">
                        <span class="slider-value" id="skoomaTempo3Value">1.0x</span>
                    </div>
                    <p class="legend">Voice speed at skooma Level 3 withdrawal/crash. Default: 1.00x.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Skooma Movement Speed (SpeedMult)</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaSpeedmult" name="SKOOMA_SPEEDMULT" min="100" max="160" step="5" value="115" oninput="document.getElementById('skoomaSpeedmultValue').textContent = this.value;">
                        <span class="slider-value" id="skoomaSpeedmultValue">115</span>
                    </div>
                    <p class="legend">How much faster she physically moves while high (100 = normal). May need an in-game re-eval to take effect. Default: 115.</p>
                </div>

                <div class="settings-slider-group">
                    <span class="slider-title">Skooma Dance Chance (per turn)</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaDanceChance" name="SKOOMA_DANCE_CHANCE" min="0" max="25" step="1" value="2" oninput="document.getElementById('skoomaDanceChanceValue').textContent = this.value + '%';">
                        <span class="slider-value" id="skoomaDanceChanceValue">2%</span>
                    </div>
                    <p class="legend">Per-turn chance she breaks into a dance flourish while high (Levels 1-2). Default: 2%.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Skooma Dance Cooldown</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaDanceCooldown" name="SKOOMA_DANCE_COOLDOWN_SECONDS" min="5" max="120" step="5" value="25" oninput="document.getElementById('skoomaDanceCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="skoomaDanceCooldownValue">25 sec</span>
                    </div>
                    <p class="legend">Minimum seconds between dance flourishes so it cannot restart mid-dance. Default: 25 sec.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Skooma First Idle Delay</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaFirstIdleDelay" name="SKOOMA_FIRST_IDLE_DELAY_SECONDS" min="0" max="30" step="1" value="8" oninput="document.getElementById('skoomaFirstIdleDelayValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="skoomaFirstIdleDelayValue">8 sec</span>
                    </div>
                    <p class="legend">Seconds to wait after the real Consume action before the first skooma idle, so the bottle/cup animation can finish. Default: 8 sec.</p>
                </div>

                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Skooma Post-Consume Animation Event or Idle FormID</label>
                    <input type="text" id="skoomaPostConsumeIdle" name="SKOOMA_POST_CONSUME_IDLE" value="IdleCivilWarCheer" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                    <p class="legend">Played once after EVERY real skooma dose (any level), after the first idle delay. Default: IdleCivilWarCheer (a one-shot cheer). Avoid "start" ceremony idles like IdleRitualStart - they hold a pose and freeze the actor.</p>
                </div>
                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Skooma Level 1 Animation Pool</label>
                    <textarea id="skoomaL1IdlePool" name="SKOOMA_L1_IDLE_POOL" rows="5" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; font-family: monospace;">IdleCO2Ceremony1Welcome
IdleLaugh
IdleCiceroAgitated
IdleCivilWarCheer
IdleGetAttention
IdleApplaud4
IdleApplaud5</textarea>
                    <p class="legend">One event/FormID per line. Level 1 high pulls randomly from this pool.</p>
                </div>
                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Skooma Level 2 Animation Pool</label>
                    <textarea id="skoomaL2IdlePool" name="SKOOMA_L2_IDLE_POOL" rows="4" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; font-family: monospace;">IdleCiceroDance1
IdleCiceroDance2
IdleCiceroDance3</textarea>
                    <p class="legend">Level 2 peak high pulls randomly from this pool.</p>
                </div>
                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Skooma Level 3 Animation Pool</label>
                    <textarea id="skoomaL3IdlePool" name="SKOOMA_L3_IDLE_POOL" rows="4" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px; font-family: monospace;">IdleWipeBrow
IdleSleepNod
IdleWarmHands</textarea>
                    <p class="legend">Level 3 withdrawal/crash pulls from this pool. No happy dance fallback is used here.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Skooma Crazed Idle Cooldown (L3)</span>
                    <div class="slider-container">
                        <input type="range" id="skoomaCrazedCooldown" name="SKOOMA_CRAZED_IDLE_COOLDOWN_SECONDS" min="5" max="120" step="1" value="18" oninput="document.getElementById('skoomaCrazedCooldownValue').textContent = this.value + ' sec';">
                        <span class="slider-value" id="skoomaCrazedCooldownValue">18 sec</span>
                    </div>
                    <p class="legend">How often the crazed idle replays while she is strung-out at Level 3. Default: 18 sec.</p>
                </div>

                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Skooma Dance Animation Event or Idle FormID</label>
                    <input type="text" id="skoomaDanceIdle" name="SKOOMA_DANCE_IDLE" value="IdleCiceroDance2" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                    <p class="legend">Editor-id animation events use CommandAnimation; hex FormIDs use PlayIdle. Default: IdleCiceroDance2.</p>
                </div>
                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Skooma Crazed Animation Event or Idle FormID</label>
                    <input type="text" id="skoomaCrazedIdle" name="SKOOMA_CRAZED_IDLE" value="IdleCiceroAgitated" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                    <p class="legend">Recurring animation for L3 withdrawal. Editor-id animation events use CommandAnimation; hex FormIDs use PlayIdle.</p>
                </div>
                <div class="form-group" style="margin: 10px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Skooma OAR Variable</label>
                    <input type="text" id="drugOarVariable" name="DRUG_OAR_VARIABLE" value="Variable09" style="width: 100%; padding: 8px; background: #252233; border: 1px solid #3A3545; color: #B8A8D0; border-radius: 5px;">
                    <p class="legend">The OAR actor variable the continuous skooma locomotion keys off (must NOT be Variable10 = the drunk one). Match this in your OAR configs.</p>
                </div>

                <!-- ---- SLEEPING TREE SAP ---- -->
                <div style="border-bottom: 1px solid rgba(253, 245, 208, 0.3); margin: 20px 0; animation: settingsBorderPulse 3s ease-in-out infinite alternate;"></div>
                <h3 style="margin: 5px 0 15px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Sleeping Tree Sap</h3>

                <div class="settings-slider-group">
                    <span class="slider-title">Sleeping Tree Sap Duration</span>
                    <div class="slider-container">
                        <input type="range" id="drugWindowHours" name="DRUG_WINDOW_HOURS" min="1" max="24" step="1" value="6" oninput="document.getElementById('drugWindowHoursValue').textContent = this.value + ' game hours';">
                        <span class="slider-value" id="drugWindowHoursValue">6 game hours</span>
                    </div>
                    <p class="legend">How long a single hit of Sleeping Tree Sap keeps her paralyzed and dazed before it wears off. Default: 6.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Sleeping Tree Sap Auto-Rouse Timer</span>
                    <div class="slider-container">
                        <input type="range" id="sapAutoRouseSeconds" name="SAP_AUTO_ROUSE_SECONDS" min="60" max="3600" step="60" value="1080" oninput="document.getElementById('sapAutoRouseSecondsValue').textContent = Math.round(this.value / 60) + ' min';">
                        <span class="slider-value" id="sapAutoRouseSecondsValue">18 min</span>
                    </div>
                    <p class="legend">Real-time fallback that stands the actor up even if no one speaks to them. Default: 18 min, matching 6 game hours at timescale 20.</p>
                </div>
                <div class="settings-slider-group">
                    <span class="slider-title">Sleeping Tree Sap Voice Speed</span>
                    <div class="slider-container">
                        <input type="range" id="sapTempo" name="SAP_TTS_TEMPO" min="0.5" max="1" step="0.05" value="0.7" oninput="document.getElementById('sapTempoValue').textContent = this.value + 'x';">
                        <span class="slider-value" id="sapTempoValue">0.7x</span>
                    </div>
                    <p class="legend">Voice slow-down on Sleeping Tree Sap (sedative). 0.70 = about 30 percent slower. Default: 0.70x.</p>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CATEGORY: EXCLUDED NPCS (CHILD PROTECTION) -->
            <!-- ============================================================ -->
            <div class="collapsible-header" onclick="toggleNestedSection('excludedNpcs')" style="background: linear-gradient(135deg, #2A2540 0%, #1C1A24 100%); padding: 15px 20px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border: 1px solid #3A3545; margin-top: 14px; transition: all 0.3s ease;">
                <h3 class="section-header" style="margin: 0; display: flex; align-items: center; gap: 10px;"><img src="images/ChimNSFWsoulgem.png" style="width: 24px; height: 24px;"> Excluded NPCs</h3>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span class="section-save-btn" onclick="event.stopPropagation(); saveSettings();">Save</span>
                    <span id="excludedNpcsToggle" class="section-toggle-btn">Open</span>
                </div>
            </div>
            <div id="excludedNpcsContent" class="collapsible-content" style="display: none; padding: 20px; background: #1C1A24; border: 1px solid #3A3545; border-top: none; border-radius: 0 0 8px 8px;">
                <p class="legend" style="margin-bottom: 15px;">Child race/flag detection is a permanent hard block. Use the editable list below for modded actors SHARMAT cannot reliably identify; exact names on that list are blocked from every affection and sex command. You can remove a manual entry later if that actor becomes an adult.</p>

                <div class="form-group" style="margin: 5px 0 15px 0;">
                    <label style="color: #FDF5D0; font-size: 13px; margin-bottom: 5px; display: block;">Manually Excluded NPC Names</label>
                    <textarea id="nsfwExcludedNpcs" class="auto-resize" placeholder="One exact display name per line" style="min-height: 110px; width: 100%; resize: vertical; background: #252233; border: 1px solid #3A3545; border-radius: 5px; padding: 10px; color: #B8A8D0;"></textarea>
                    <p class="legend" style="margin-top: 6px;">Case-insensitive exact display-name matching. One name per line is recommended; commas and semicolons also work.</p>
                </div>

                <div class="form-group" style="margin: 5px 0 15px 0;">
                    <label style="color: #9988BB; font-size: 12px; margin-bottom: 3px; display: block;">Child Frame Prompt (injected into the character block)</label>
                    <textarea id="childProtectionFrame" class="auto-resize" style="min-height: 90px; width: 100%; resize: none; overflow: hidden; background: #252233; border: 1px solid #3A3545; border-radius: 5px; padding: 10px; color: #B8A8D0;">You are a child, not an adult. The people around you are grown-ups - some strangers, some not. You react like a kid would: curious, playful, blunt, sometimes shy or bratty. You do NOT flirt, and you never read an adult's attention, gifts, or kindness as romantic - children do not experience the world that way.</textarea>
                </div>

                <h3 style="margin: 20px 0 12px; color: #FDF5D0; font-size: 16px; animation: subPulse 3s ease-in-out infinite alternate;">Detected Child Blocks <span style="color:#7A6890; font-size:12px;">(locked)</span></h3>
                <?php
                $__excludedChildNpcs = [];
                try {
                    if (isset($GLOBALS["db"]) && function_exists('isNpcBlockedFromNsfw')) {
                        // Union of CHIM's rolling NPC table and Sharmat's persistent per-NPC store.
                        // core_npc_master is trimmed by CHIM, so child flags must also be sourced
                        // from nsfw_npc_data (which persists every NPC we've ever processed).
                        $__seenNames = [];
                        foreach (["SELECT npc_name FROM core_npc_master", "SELECT npc_name FROM nsfw_npc_data"] as $__q) {
                            try { $__rows = $GLOBALS["db"]->fetchAll($__q); } catch (Exception $__qe) { $__rows = []; }
                            if (is_array($__rows)) {
                                foreach ($__rows as $__r) {
                                    $__nm = trim((string)($__r['npc_name'] ?? ''));
                                    if ($__nm === '') continue;
                                    $__seenNames[strtolower($__nm)] = $__nm;
                                }
                            }
                        }
                        ksort($__seenNames);
                        foreach ($__seenNames as $__nm) {
                            $__b = isNpcBlockedFromNsfw($__nm);
                            if (!empty($__b['blocked']) && in_array(($__b['reason'] ?? ''), ['child_npc', 'is_child_flag', 'child_race'], true)) {
                                $__excludedChildNpcs[$__nm] = $__b['reason'];
                            }
                        }
                    }
                } catch (Exception $__e) { $__excludedChildNpcs = []; }
                ?>
                <?php if (empty($__excludedChildNpcs)): ?>
                    <p class="legend">No child NPCs detected in the current NPC list yet. They'll appear here as you meet them.</p>
                <?php else: ?>
                    <div style="display: flex; flex-wrap: wrap; gap: 10px 18px; padding: 12px; background: #15131D; border: 1px solid #3A3545; border-radius: 6px;">
                        <?php foreach ($__excludedChildNpcs as $__cn => $__reason): ?>
                            <span class="npc-purple-glow" title="locked (<?php echo htmlspecialchars($__reason, ENT_QUOTES, 'UTF-8'); ?>)">&#128274; <?php echo htmlspecialchars($__cn, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="legend" style="margin-top: 8px;">These actors are blocked by child name, child flag, or child race independently of the manual list.</p>
                <?php endif; ?>
            </div>

            <div class="button-group" style="margin-top: 25px;">
                <button class="btn-primary npc-action-btn" onclick="saveSettings()">Save Settings</button>
                <button class="btn-secondary npc-action-btn" onclick="resetSettings()">Reload Settings</button>
                <button class="btn-danger npc-action-btn" onclick="resetSharmatData()" style="min-width: 260px;">Clear All SHARMAT Data</button>
            </div>
