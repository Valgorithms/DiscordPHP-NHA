# Changelog

All notable changes to DiscordPHP-NHA are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project uses
SemVer with the **major tracking the NHA world API version**.

## [Unreleased]

## [3.23.0] - 2026-09-26

### Added
- **The server's rule changes become an issue, not a surprise.**
  `upstream-watch.php` (`composer upstream:check`) compares the live NHA server
  with the baseline this code was written against. It covers the operator
  rule-update feed (`/updates`), the API contract (`/openapi.json`), the
  crafting codex (`/rules`), the colony and terraform bills (`/expansion`) and
  the public engine source (`Recluse/nha-mmo`). Each is reduced to its rules
  (`NHA\Upstream\Snapshot`): player inventions, deliveries and discoverers
  are left out, so a difference means the rules moved, not that the game was
  played. `NHA\Upstream\Drift` then says what each rule is now: operator
  updates quoted in full, reworded documentation as a diff, a changed bill
  with its old and new amounts, and new engine commits with the constants
  their patches assign.
- `.github/workflows/upstream-watch.yml` runs it every three hours with
  `--issue` and keeps one issue labelled `upstream-drift` in step: opened when
  the server moves, rewritten with a comment when it moves again, closed when
  the baseline catches up. The body is a brief for whoever updates the code,
  saying where in this repository each kind of change lands. After updating,
  `composer upstream:accept` (or `--accept=rules,source`) makes the live
  server the new baseline (`upstream/*.json`, `openapi.json`).
- `Endpoint::OPENAPI`.

### Fixed
- **The committed `openapi.json` was mojibake.** It had once been decoded as
  Windows-1252 and saved again, so every `—` read `â€”`: 24 strings, which
  made seven schemas look changed. It is repaired, not refreshed, so it is
  still the contract the code was written against. The first check therefore
  reports the real drift since then: `GET /vault`, `GET /treasury`, a `token`
  on `/observe` that unlocks the agent's Vault fragments, an `agent` filter on
  `/log`, and the documented intent rate limit.

## [3.22.1] - 2026-09-26

### Fixed
- **The agent was not ready for the fix 3.22.0 prepared it for.** The loop
  breaker ate the one thermal_core (`brine+thermal_core`) before 3.22.0 fenced
  it off, and since 3.21.0 the supply run skipped bodies no ship can reach, so
  nothing would make another. Had Triton's Δv come down, the agent would have
  been sent there without the thing it needs to board. The run now makes the
  gear for an unfounded body whether or not it is in reach today:
  `SupplyRun::need()` drops its `$unreachable` argument. Reach is still what
  decides whether the agent flies there.

## [3.22.0] - 2026-09-26

### Added
- **Ready for a Triton fix.** Triton is parked because the engine asks for Δv
  320 and no ship can make 300 — a number in the engine's table, which a
  balance fix would lower. The park was permanent. Now the Δv the engine asked
  for is kept with it (`recordOutOfReach($agent, $body, $need)`,
  `outOfReachNeeds()`), and every turn `reopenReach()` compares it with the live
  `dv_need`: once that drops below the ceiling, the body comes off the parked
  set, the depart-unreachable set and the capability ledger
  (`clearOutOfReach()`, `clearCapability()`), and the status line says
  `🔭 back in reach: triton (Δv 280, was 320)`. The ordinary flight logic then
  takes it from there. A 3.21.0 bare list still reads, as unknown needs.
- `GameData::dvNeeded()` — the Δv a "Δv too low" refusal asked for.

### Fixed
- **The entry gear could be researched away.** The thermal_core (and the
  gate crates) were not protected: the model's combine gate and the loop
  breaker's research pass could both spend them. Both now treat them like
  fuel and shields.

## [3.21.0] - 2026-09-25

### Added
- **Bodies no ship can reach are remembered as such** (`recordOutOfReach()` /
  `outOfReach()`). Kept apart from the depart-unreachable set, which also holds
  thrust and landing-gear refusals a better hull fixes and is wiped on every
  `finalize`; nothing wipes this one.

### Fixed
- **A body no ship can reach started a hull rebuild.** Holding Triton's
  thermal_core made Triton read as a destination the current hull could not
  serve, so the dead-end-hull path began gearing a replacement ship ("dead-end
  hull — ship — combine composite for a light frame/wings"), and the ladder's
  own fallback did the same. No hull fixes a Δv past the ceiling, and the
  rebuild's `finalize` would have wiped Triton's parking too. Out-of-reach
  bodies are now excluded from both the dead-end test and the ladder's
  `_colony_done` view.
- **The Destinations list still invited a trip to Triton** ("colony NOT
  FOUNDED — someone must land there"), and the plan chased it. A parked body now
  reads UNREACHABLE.
- **The supply run no longer starts for a destination no ship can reach.**

## [3.20.2] - 2026-09-25

### Fixed
- **Triton is out of reach, and is now treated as such.** With the
  thermal_core home, the agent departed for Triton and the engine answered
  "your ship makes 133 but Triton needs 320". The engine's rocket equation
  approaches `ve·eff/5` however much fuel is loaded — 300 at best, helium3 on an
  ion drive — so no hull on any load clears 320. "Δv too low" was read as a
  wait for fuel, so the agent would have held in orbit for every Triton window,
  forever. `GameData::DV_CEILING` / `dvOutOfReach()` now mark such a refusal a
  hull limit: the outcome handler parks the body unreachable, the
  classifier files it as `capability` (and now knows the outer bodies' names
  when the feed carries no args), and the refusal memory treats it as durable.

## [3.20.1] - 2026-09-25

### Fixed
- **The supply run stopped on Mars.** The first live run packed, shed
  3,717,052 `c_regolith`, warped to Mars on 3 crates, mined and made its
  `thermal_core` — and then handed the turn back, because `need()` only fired
  while the gear was missing. The finished-colony machine held for a window
  the gate does not need, hit its strand limit and called `distress` (-20 HP;
  the leftover Mars cargo jettisoned; the thermal_core, not exotic cargo, came
  home). The run now lasts until the gear is home: held but still on the supply
  body counts as running, so the return leg it already had is actually reached.
  The earlier tests called that leg directly, never through `need()`; the new
  ones go through it.

## [3.20.0] - 2026-09-25

### Added
- **The supply run (`Brain\SupplyRun`).** Triton is unfounded and needs a
  `thermal_core` in the hold to land; a thermal_core is a battery + `mars_ice`
  + a non-magnetic metal, and `mars_ice` is mined only on Mars. Nothing could
  make that trip: the depart gate never picked a finished colony, and on a
  finished body the only job was the trip home. The run now does it, for gear
  an unfounded destination needs, every move read off the observation:
  - pack at home: two batteries, copper, the body's arrival items and, for a
    gated route, eight `warp_container` crates, each bought or batch-crafted
    from recipes the world already knows;
  - ride to the depart band and shed exotic cargo into a 1-credit sell order
    until the crates can carry the rest (a gate refuses uncrated body cargo,
    and the agent's extractors add about ten `c_regolith` a tick);
  - warp to Mars, land, mine six `mars_ice` a turn, and combine the
    thermal_core there, since it is not exotic cargo and needs no crate;
  - shed again and depart for Earth from the surface.
  It is submitted directly, like combat (source `supply`, `🚚` on the status
  line), so the gates written for the model's picks do not unpick it. A
  refusal of its own move that shedding cannot fix stands it down for 600
  ticks (`SupplyRunStateTrait`); a refusal for uncrated cargo is answered by
  shedding again.
- `GameData::EXPANSION_CARGO` and `WARP_CRATE_CAP`, transcribed from the
  engine.

## [3.19.0] - 2026-09-25

### Added
- **Plan steps are ticked off from the observation.** The model was asked to
  report its own progress with `"step_done": true` and never did: 162 live
  turns on step 1, "hold 1 aluminum", with 4 held. `Planner::stepMet()` now
  checks a step in one of the forms the planner is asked to use ("hold N item",
  several joined by "and"; "be at <body>", surface or orbit; "be in orbit";
  "be at (x, y)"), and every step the observation shows done is passed before
  the model is asked, several in one turn if need be. Anything else still
  waits for the model's `step_done`. The status line says
  `🗺️ step 2/4 done (seen in the observation) — next: …`.

### Changed
- The spelling map moved to `GameData::ALIASES` / `GameData::canonical()`, so
  the step check and the argument respelling share it.

## [3.18.0] - 2026-09-25

### Added
- **An intent the game just refused is not sent again.** The engine's
  refusals were shown to the model and it resent them anyway: `buy aluminium`
  six times in 86 ticks, "depot doesn't trade aluminium" in its prompt each
  time. A refused intent is now remembered verb and args exactly
  (`recordRefusal()`), for 300 ticks, or 60 when the reason is a try-again-later
  one (`RejectionClassifier::isTransient()`), and the same intent is swapped
  out before submission. The model sees the block under BLOCKED.

### Fixed
- **The game spells it `aluminum`.** Our system prompt, prompt notes and
  ladder reasons said "aluminium"; the model copied it into `buy` and `combine`,
  and the shortage gate, finding 0 `aluminium` beside 4 `aluminum`, turned a
  good composite `combine` into a refused buy. Every model-facing text now
  uses the game's spelling, and known aliases in the model's `resource`,
  `ingredients` and `with` are mapped before any gate reads them.

## [3.17.0] - 2026-09-25

### Added
- **The planner reviews its own draft.** The first live plan made a
  `thermal_core` from `mars_ice` with none in the hold and no step going to Mars
  for it, and wrote every step as a command. `Planner::critique()` now flags a
  body resource the plan needs (named in it, or in the recipe of an item it
  names) that the agent does not hold and no step gets, and steps written as
  commands rather than milestones. A flawed draft goes back once with the
  problems listed; the revision is used if it parses. Live, the revision added
  the depart to Mars and the `mars_ice` mining. The status line says
  `(revised after review)`. Generic clauses such as "an electrolyte" are left
  alone: the game matches ingredients by physics tags.
- **`GameData::BODY_MINE`** and `GameData::minedOn()`: which body's `mine`
  yields which resources, transcribed from the engine's `BODY_MINE`. The
  planner's brief lists it.

### Fixed
- **Stalls replanned too eagerly and reset progress.** Loop breaks fire on
  about one turn in five, so a stall replanned every 150 ticks: live, twice in
  400 ticks, the same goal each time, back to step 1 each time. A stall now
  replaces a plan only once it has had 450 ticks to work, and a plan handed back
  unchanged keeps its current step (`🗺️ plan reviewed, unchanged: …`).
- **Steps merged into one string are split back.** Under the schema the model
  sometimes closed and reopened its quotes inside one step, so three steps
  arrived as one.

## [3.16.0] - 2026-09-25

### Added
- **A strategist (`Brain\Planner`).** The turn model picks one verb a turn and
  remembers nothing between turns; 81% of its turns were `mine` or `move`,
  because the way forward was a chain no rule encodes (fly to Mars, mine
  `mars_ice`, make a battery and a `thermal_core`, fly to Triton, found the
  colony). A second, rarer call to the same model now sets one goal and 2–6
  checkable steps, schema-enforced. The plan is stored (`PlanStateTrait`) and
  shown in every turn prompt with the current step marked; the turn model adds
  `"step_done": true` when that step is visibly complete. It is revised when
  missing, finished, 1,200 ticks old, the agent reaches or leaves a body, or it
  stalls (repeated loop breaks, an overrun hold, one proposal blocked three
  times since it was set), never within 150 ticks of the last ask. The call
  runs after the turn's own and is not awaited. Plan changes ride on the status
  line (`🗺️ new plan: …`, `🗺️ step 1/3 done — next: …`) and the turn record
  gains `plan` (`2/4` or `done`). `NHA_PLANNER=0` turns it off.
- **`env.example`** documents every variable `bot.php` and `autoplay.php` read,
  with comments on their own lines (neither `.env` loader strips a trailing
  `# comment`). The README, `.gitattributes` and agent guides point to it.

## [3.15.0] - 2026-09-25

### Added
- **The model is told when our own gates block its pick.** The engine's
  refusals reached it through the activity feed; our gates' never did. Live, it
  proposed `finalize` 161 times in 8 hours and was blocked 142 times for "no
  cockpit" without being told, and kept giving "build the flyer" as its reason
  while those builds were swapped out. A replaced pick is now stored
  (`recordVeto()`, one row per distinct proposal, counted) and shown for 600
  ticks under "BLOCKED before reaching the game". The "Last turn" line now says
  "you proposed X, but a safety check replaced it with Y" instead of crediting
  Y to the model.
- **Every turn records who decided it.** `AutoPlayer::lastTurn()` returns the
  turn's `source` (`model`, `adjusted`, `override`, `loop`, `ladder` or
  `defence`), the model's original pick when it was replaced, and how long the
  model took (`AgentBrain::lastLatencyMs()`). `autoplay.php` logs it as each
  line's JSON context, and `recordDecision()` stores `source` and `proposed`.
  Measuring the model's share used to mean guessing from how each reason was
  worded.

### Fixed
- **The loop breaker's `wealth` turn sold while rich.** With 440k credits it
  still sold 20 crystal at the depot, the liquidation 3.14.3 stopped in the
  shared fallback. It now sells only below `CREDIT_FLOOR`, never a protected
  stockpile line, and otherwise falls through to harvesting or relocating.
- **"Last turn's X was rejected" never appeared in the status line.** The
  closure that builds it did not capture `$pre` or `$last`, and `??` hid both
  undefined variables, so the line was always empty.

## [3.14.4] - 2026-09-25

### Fixed
- **The fallback and loop-break paths decided without this turn's hints.**
  `step()` injects `_colony_done`, `_fuel_goal`, `_board_wants` and
  `_combine_lore` into its view of the world, but `fallbackDecision()` and
  `loopBreakDecision()` rebuilt a bare view from the observation and handed THAT
  to the ladder. Without `_colony_done` the ladder believed there was still
  somewhere to fly, so its gear-up path won. The loop-break path alone ran 279
  times in 8 hours. Both now merge the turn's hints (`withHints()`).
- **A second hull was being built.** For 8 hours the model was "building the
  flyer bundle to reach orbit" while owning a flying ship, with a
  `thermal_core` — not a hull — standing between it and Triton. The
  no-second-hull guard kept the model's `build` whenever its own fallback came
  back as another build, and the part-cap gate then walked it through the
  bundle one part at a time. It now falls through to `earnStep()` instead, and
  it also fires when there is nowhere left worth flying — the one state that
  reads as "no capable ship", where it used to stand aside.

## [3.14.3] - 2026-09-25

### Fixed
- **A vetoed verb liquidated the hold.** `earnStep()` — the fallback every
  last-gate veto routes through — sold a surplus FIRST, whatever the balance.
  With 439,968 credits in the bank, the model's repeated `finalize` and `build`
  proposals (vetoed: no cockpit, or a flying ship already exists) turned into
  233 depot sales of iron, silicon and copper in 8 hours, at half value, each
  reading "no credits to buy with". It now sells only below `CREDIT_FLOOR`;
  otherwise it moves on to the ladder's next step.

## [3.14.2] - 2026-09-25

### Fixed
- **An inert hull could be finalized.** Given the new destination digest, the
  model decided to "build the flyer to reach the outer system" — the obstacle
  was a `thermal_core`, and it already owns a working flyer — then finalized a
  single bare frame into vehicle #151777, *"drives=False v=0 flies=False"*. The
  engine accepts that, so no refusal gate saw it, and it is irreversible: every
  loose part is consumed into a vehicle that does nothing. `finalize` is now
  refused for a bundle with no cockpit (no control) or no engine, jet or
  propeller (no motion) — the engine's own rule. Drones are vehicles too, so it
  blocks only the inert case, and the ladder's own finalize paths are all behind
  `flyerReady()`, which already requires both.
- **The `build` gate never billed the upgrade item.** It checked
  `GameData::BUILD_COST[part]` alone, but the engine adds each `with:` item to
  the bill — *"insufficient for jet (need {metal: 10, crystal: 2, ion_thruster:
  1})"* — so a model `build jet with ion_thruster` went out with no thruster in
  hold, for a guaranteed refusal.

## [3.14.1] - 2026-09-25

### Fixed
- **Money was filed into a colony nobody had founded.** 3.14.0's first live
  decision was `invest {triton, geyser_mast, 500}` — and the engine refused it:
  *"no colony board on Triton yet — somebody has to GO there and lay it with
  construct{shape:'colony',body:'triton'} before it can be financed from afar"*.
  `/colony/{body}` publishes a board's full module plan before anyone has laid
  it; `colony_exists` says which, and nothing in the brain ever read it. So the
  invest path would have re-filed the same refusal once per cooldown, forever.
  `endgameInvest()` now declines an unfounded board, and
  `Objectives::openModules()` carries a `founded` flag so an unfounded board is
  never "creditable". A missing key is treated as founded, which every board
  before Season 8 was.

### Added
- `Objectives::unfoundedBodies()`, recorded with each objective-board read.
- The model's destination digest, the board digest and the system prompt all
  say when a colony is **NOT FOUNDED**, and what founding it takes — so the goal
  is the body itself, not money it cannot yet accept.

## [3.14.0] - 2026-09-25

### Fixed
- **A permanent orbital "hold" for a destination that could never be reached.**
  Agent #142285 spent days riding the elevator up and down, "holding for a
  transfer window to open" for Triton — the last body with colony work. The
  window was never the obstacle: Triton needs a `thermal_core`, and no rung makes
  one. `Ladder::isHoldingForWindow()` asked only "is there no departable target
  *right now*", which is true forever once nothing reachable has work left. It
  now requires a live destination actually waiting on a window.
- **The hold switched off the only escape hatch.** Holding suppresses the loop
  guard, and the loop guard is the road to escalating a stall to the model — so
  in four days the model was never once asked. A hold is now bounded by its own
  estimate (the soonest reachable window, read when it began, plus
  `AutoPlayer::HOLD_GRACE_TICKS`); past that, the hold is declared failed and
  the loop guard comes back.
- **The invest-from-Earth path only ever looked at finished colonies.** The
  remote board rotated through `$doneBodies`, which by definition have nothing
  left to fund. It now prefers a body with work left that the agent cannot
  reach — Triton, with zero funders on all three modules and 418k credits in the
  agent's bank — and falls back to the old rotation.
- **`departTarget()` was called bare, directly under a comment warning that a
  bare call "silently defaults to an empty skip list".** The ladder itself would
  have flown to a colony finished weeks ago the moment its window opened.
- **The hold check was given the hull-rejections list, not the full skip list**,
  so a finished colony counted as a reason to keep waiting.
- **"Nowhere to go" was read as "no ship"** and would have geared up a second
  flyer. `$hasShip` is false both when the hull cannot reach anywhere (rebuild
  it) and when there is nowhere worth reaching (a new hull changes nothing). The
  two are now told apart, using colony-done on its own (`_colony_done`), since
  the merged skip list cannot distinguish them.
- **A leftover strand counter was a loaded gun.** `agent_home_holds` sat at 65
  from an old trip, past the 40 that calls `distress` — the next underfuelled
  return would have spent HP and jettisoned its whole haul on its first turn.
  It is now cleared whenever the agent is not at, or returning from, a body.
- `endgameInvest()` no longer promises every investment "cuts Mars+Venus Δv",
  which only a moon base delivers.

### Added
- **`Bodies::reachable()` / `missingGear()` / `gateLinked()` and
  `Bodies::CRAFTABLE_GEAR`.** A destination is reachable when every item its
  arrival consumes is in hand or has a crafting rung. `CRAFTABLE_GEAR` is the one
  deliberately hand-kept table: it describes this code, not the game.
- **`Ladder::departRefusal()` — a last gate on `depart`**, the final refusable
  verb that reached the engine unchecked when the model proposed it. It judges
  only what the ENGINE would refuse (gear not in hold, a shut window with no gate,
  a gate that has refused our cargo, a hull already refused) and never whether a
  trip is worth making: an earlier cut also refused finished colonies, which
  would have vetoed flying to Mars for the `mars_ice` a `thermal_core` needs.
- **A warp gate's cargo refusal is learned from the engine's own words**
  (`recordGateCargoRefusal()`), not predicted from a list of exotic resources.

### Changed — what the local model is given
- **A per-destination digest** built from the engine's own preflight: Δv,
  window or gate, each piece of arrival gear marked `(have)` / `(MISSING)`,
  whether the colony still has work for this agent, and the engine's blockers
  in its own words. The model used to see `titan OPEN` and nothing else.
- **Recipes for missing gear, from the `/rules` codex**, with one level of
  sub-recipe (a `thermal_core` needs a battery; a battery has a recipe too). The
  codex fetch had been throwing this text away every turn.
- **Rejections persist across turns** — the engine's recent refusals in its own
  words, deduplicated with numbers stripped. A rejection used to be shown for
  exactly one turn, so the gate refusal was forgotten and re-earned every cycle.
- **`how` is no longer cut at 400 characters.** The gear recipes ("PACK BEFORE
  YOU FLY…") started after that, so they were always the part cut off.
- **The "SUGGESTED next action" is computed from the ladder's own view** — its
  real stance, skip list and injected hints. It had run as `homestead` with an
  empty skip list on the bare observation, a different agent from the one
  deciding.
- **The system prompt no longer lists four destinations.** The `depart` verb
  reference named deimos/phobos/mars/venus only, telling the model the outer
  bodies were not valid. It now points at the live list, and a new *WHEN YOU
  CANNOT GET THERE* procedure walks the model from a blocked destination to its
  missing gear, that gear's recipe, the body that yields its inputs, and — when
  nothing is reachable — `invest` from where it stands.
- A hold that overruns its estimate is named to the model as **HOLD FAILED**.

### Fixed
- **`composer install` could not resolve from Packagist.** DiscordPHP's
  `dev-master` now requires `discord-php/http ^10.9.8`, which the
  `dev-master as 10.1.7` alias cannot satisfy. The alias is now
  `dev-master as 10.9.8`. Machines that link the sibling checkouts through the
  global Composer config never saw the failure.

## [3.13.1] - 2026-09-21

### Fixed
- **The world codex silently disabled the exhaustion rule.** 3.13.0 counted an
  ingredient as productive if it appeared anywhere in `/rules` — and the live
  codex holds 138 recipes, so very nearly every raw material is in it somewhere,
  `herb` included. Against live data `exhausted` came back EMPTY and the
  nine-filing herb sweep would have resumed on fresh partners, which is the one
  thing the rule exists to stop. Productivity is now measured from this agent's
  own grants; the codex only vouches for an ingredient we have no verdict
  history on, and can never overrule a record of refusals against us. *"Is this
  pair already invented"* is the codex's question and the caller asks it
  separately.

## [3.13.0] - 2026-09-21

### Added
- **`Brain\Referee` — the Guild's verdicts are now read back, not just paid
  for.** Every filing costs 50 credits, four in five world-wide are refused, and
  the referee explains WHY in prose that is kept on the agent's profile forever.
  The brain was reading those, logging them, and forgetting them. Two patterns
  account for nearly all of #142285's 24 recorded rejections:
  - **A crafted item carries no tags, so it can never be an ingredient** —
    *"'composite' has a 'shaped' tag, but 'healing_salve' does not have any
    tags"*. After inventing `medicinal_potion` the agent tried it against
    composite, carbon, c_regolith and brine and was refused four times; it then
    invented `healing_salve` and tried **the same four partners** for the same
    four refusals. Eight filings, 400 credits, one rule learned twice.
  - **An ingredient that only ever fails is spent** — `herb` was filed against
    nine different partners for nine refusals and no grant.

  `Ladder::speculativeCombine()` and the model-side combine gate both consult it,
  so a pair the Guild has already ruled on is never filed again.

### Fixed
- **`ore` would have been written off as spent, and it is the best ingredient we
  have.** It was refused six times (wire, water, salt, metal, ice, glass) while
  also being the ingredient in BOTH granted recipes — `nickel_ore_ingot` and
  `silicon_ore_alloy`. An `invent` milestone records only the OUTPUT, never the
  inputs, so matching on those alone misses it entirely. Productivity is now
  taken from the `/rules` codex (which does name inputs), with the recipe name
  as a fallback; an ingredient that has ever produced anything is never spent.
- **The tagless item was read together with the grammar around it.** The referee
  names it mid-sentence — *"…, while healing salve has no defined properties"* —
  and a greedy capture yielded `while_healing_salve`, which matches nothing and
  silently disabled the rule it was there to enforce.

## [3.12.1] - 2026-09-21

### Fixed
- **A restarted runner sat out the full lease TTL on Windows.** `autoplay.php`
  hands the lease back on SIGINT/SIGTERM — but Windows has no signals to
  deliver, so that path never ran there and every stop orphaned the lease. The
  next runner then skipped ~45s of turns logging *"another driver holds the
  lease"* at a process that no longer existed. Three layers now cover it:
  `sapi_windows_set_ctrl_handler()` for Ctrl+C and Ctrl+Break, a
  `register_shutdown_function` for any exit that still runs PHP's shutdown
  sequence, and — for the kills no handler can catch (`Stop-Process -Force`, a
  crash) — an exclusive OS lock held for the driver's whole lifetime. The kernel
  drops that lock when the process dies however it dies, so taking it is *proof*
  the previous driver is gone. Liveness is never guessed from the holder's PID:
  PIDs get recycled, and being wrong that way means two drivers submitting two
  intents per interval from one token.
- **The driver lock could not be created on a first run**, because the state
  directory did not exist yet — so the first driver claimed nothing, `save()`
  then created the directory, and the second driver found the lock free and read
  a live first driver as dead. Exactly the two-driver outcome the lease exists
  to prevent.

## [3.12.0] - 2026-09-21

### Added
- **`Brain\Vault` — Season 8's seal, which cannot be opened alone.** Six symbols
  in order from an alphabet of twelve, six obelisks across four gravity wells,
  and standing at one reveals exactly one symbol to that agent. The brain now
  reads its own fragments, walks to any obelisk on ground it is already on
  (reading takes no verb — the stone lights up on arrival), trades symbols in
  world chat against the roster the engine publishes in
  `vault.ask_these_agents`, and speaks `unlock{code}` on Titan the moment all
  six are in hand. It **never guesses**: a partial code is a chat message, never
  an attempt, because a wrong one freezes the stone for 90 ticks and reveals
  nothing about how close it was.
- **`observe` now sends the agent token** (as an `X-Agent-Token` header, never
  `?token=`, which would put a secret in access logs and proxies). `GET /observe`
  is open to everyone, so it will not hand out an agent's own fragments to an
  unproven caller — without the token `vault.your_fragments` reads `"hidden"` and
  the brain cannot tell "I hold none" from "I was not asked to prove it".
- **`Ladder::stockTargetFor()` — the mining band is demand-aware.**
  `MINE_STOCK_TARGET` was a flat 1,000 for every resource in the game, which is
  right for a line something wants and absurd for one nothing does: the agent
  chopped wood toward 1,000 at a unit a turn while the only open colony board
  asked for something else entirely. Targets now follow demand — a board ask or
  a pending craft keeps the deep band, a **non-tradeable** line keeps it too
  (mining is its only source, and the colony asks that matter most are exactly
  these), and only a tradeable line nothing wants narrows, to
  `MINE_IDLE_TARGET`. That last case is safe precisely because such a line can
  simply be bought if a need turns up.

### Fixed
- **A body-surface `construct` could be emitted with an empty `body`.** The
  surface test accepts several observation shapes and only one carries a body
  name, so a match on the others stamped `body: ""` into the args — a guaranteed
  refusal, which leaves the observation unchanged and spins. Live Earth reports
  `place.where: earth_orbit` so it does not trigger today; the guard is closed
  regardless.

## [3.11.0] - 2026-09-21

### Added
- **`Brain\Bodies` — destinations read from the world, not from a constant.**
  Everything the brain had transcribed by hand is already published per
  observation: `expansion.windows[body]` carries `dv_need`, `transit_ticks`,
  `open` and `opens_in`, and `expansion.preflight.destinations[body]` carries
  `needs_in_hold`, `needs_landing_gear_on_ship`, `min_thrust_to_weight`,
  `course_correction_fuel`, `ready` and `blockers`. `Bodies` merges the two and
  serves them cheapest-Δv first.
- **Course-correction fuel is now modelled.** The crossing bills fuel beyond the
  departure burn — 4 units to Deimos, 13 to Triton over its 260 ticks — and no
  constant carried it, so nothing counted it. Being short does not get the
  `depart` refused; it strands the agent on arrival.

### Fixed
- **Season 8's three new bodies were invisible.** The world now offers
  `enceladus`, `titan` and `triton` alongside the original four, while
  `Ladder::DEPART_ORDER`, `GameData::DV_NEED`, `TWR_DEPART` and `GEAR_BODIES`
  all still named four. The agent could not see three of the seven places it was
  allowed to fly — including Triton, the only body with open colony work (0/3).
  `departTarget()`, `liveDestinations()` and `GameData::assess()` now enumerate
  from the observation.

### Deprecated
- `Ladder::DEPART_ORDER`, `GameData::DV_NEED`, `GameData::TWR_DEPART` and
  `GameData::GEAR_BODIES` are the offline fallback now, not the truth. They still
  answer when an observation carries no `expansion` block, and a live observation
  always wins. A missing preflight row falls back to what a body was last known
  to demand, so a gap can only ever ask for more gear than the engine would,
  never less.

## [3.10.2] - 2026-09-15

### Fixed
- **A credit emergency now outranks routine stockpiling.** Rung 3a stands down
  when the agent is under `CREDIT_FLOOR` and a sale would genuinely fire. It was
  written to stop the agent *spending* credits while standing on a deposit, and
  quietly blocked it from *raising* them too: #142285 stood on wood with 2
  credits and 4,774 crystal and chopped one unit a turn toward a target of 1,000
  — four hours grinding a 2-credit line while too broke to buy fuel, pay a filing
  fee, or fund a module with money. A pending craft still wins; filling up for
  its own sake does not, and a pile inside the hoard cap is still not a glut.

## [3.10.1] - 2026-09-15

### Fixed
- **Protecting a restock line had no ceiling, and that wedged the agent from the
  other side.** `Ladder::protectedLines()` shielded `metal` and `crystal`
  unconditionally, which is right while the agent is accumulating them and a
  bonfire once the pile dwarfs anything restocking would want. Home from Venus
  with 4,758 crystal — nineteen times `Stance::RESTOCK_EXIT` — and 14 credits,
  #142285 ground `chop` → `sell 20 wood` → `chop` for about 40 credits a cycle
  while the one pile the depot would pay for sat untouchable. Past `capFor()` a
  restock line is a glut, and the `sell` rung is now allowed to see it.

## [3.10.0] - 2026-09-15

### Fixed
- **The trip home could not be flown once every body was funded.** The return
  gate asked `Ladder::hasDepartCapableShip()`, which is false when no OUTBOUND
  destination is left — and Earth is not in `DEPART_ORDER`, so an agent that had
  visited all four bodies could never be judged able to come home. Agent #142285
  sat in Venus orbit one Mars funding short of a permanent hold. Flying home now
  asks only whether a ship exists.
- **`return_dv` was spent as if it were a tankful.** It is a Δv. At the 130 Δv of
  a Venus return, `900·L/(mass + 5·L)` puts the real requirement near 440 units
  on a ~845 hull — not the 145 that `max(45, return_dv) + 15` stocked toward. The
  bar is now the fuel goal solved from the engine's own rejection
  (`Ladder::fuelTargetFromRejection()`), floored by the Δv.
- **`depart` was the last refusable verb still fired blind.** In the depart band
  the only test was `fuel >= 1`, so the moment a window opened the agent burned a
  turn on a burn the tank could not make, got an unchanged observation back, and
  fired again. It now waits for the real bar — with a single probe allowed while
  no goal has been learned, because the rejection is what teaches the number.
- **Riding up on a tank that could never be filled.** The elevator rung only
  checked the window, so the agent left the ground — and the depot, and anything
  it could mine — underfuelled. It now rides only with the trip home paid for,
  and a grounded agent short of both fuel and credits works through `earnStep()`
  instead of holding.

### Added
- **`distress` as the terminal escape.** The engine documents it as the recall
  for a stranded agent and the brain had no rung for it. The depart band is the
  one place with nothing to mine, no depot and no way down that does not cost the
  band, so a genuinely stranded agent held there forever. After
  `AutoPlayer::HOME_HOLD_STRAND` turns of real deadlock — a shut window never
  counts, and a full tank never counts — it calls for recall. It costs HP and
  jettisons the body haul, which is why it is last; a recalled agent is playing
  again and a held one never will be.
- `StateStore::countHomeHold()` / `clearHomeHolds()`, which tell a legitimate
  wait-for-the-window from being stranded.

## [3.9.7] - 2026-09-14

### Fixed
- **Vanity spires again, from the other side.** 3.9.6 stopped the agent
  *reading* as flight-ready on a token of fuel, but the guard delegates to
  `Ladder::suggestion()` — whose own tower rungs then returned the same
  `construct`, so the swap was a no-op and the spires kept going up.
  `isGearingShip()` goes false the moment a hull exists, which re-opens those
  rungs; a ship in hold is not the end of the mission's claim on the agent's
  materials. Both tower rungs now also stand down while the tank is short of
  the transfer (`_fuel_goal`), so composite, metal and credits go to the fuel
  that lets it leave rather than builder points.

### Lesson
- A guard that fixes a decision by asking the ladder for a replacement is only
  as good as the ladder's own answer. Check what the fallback returns in the
  failing case — twice now a correct guard has been defeated by the thing it
  delegated to.

## [3.9.6] - 2026-09-14

### Fixed
- **A token of fuel counted as a fuelled ship.** The anti-vanity-spire guard
  tested `cryo_fuel > 0`, so with **126 units against the 546** a Venus
  transfer needs the agent read as flight-ready, the guard switched off, and it
  spent its turns raising pyramids and spheres for builder points while the
  window it could not take counted down. Readiness now measures against the
  learned `_fuel_goal` — the same bar 3.5.5 solved from the engine's own Δv
  rejection.
- The on-ground fuel rung had the matching half of the bug: it stocked toward
  `DEPART_FUEL_MIN` (90, moon-sized) rather than the goal, so at 126 units it
  declared the tank full and moved on. It now targets the goal, like the
  orbital hold rung already did.

### Lesson
- The same mistake twice in one file: a threshold that means "some" standing in
  for one that means "enough". 3.5.5 fixed it for the hold; these were the two
  places that still asked the cheap question.

## [3.9.5] - 2026-09-14

### Fixed
- **A guard that says "earn it first" has to earn it.** 3.9.4's Guild-fee gate
  blocked the novel `combine` and then fell back to the idle no-op, so nothing
  changed and the same block re-fired every turn: *"a novel filing costs 50 and
  the purse holds 43 — earn it first"* on repeat, while the agent sat on **593
  iron** — one sale from clearing the gap. The gate had simply moved the spin
  rather than ending it.
- All four gates shared that fallback (`buy`, `sell`, `build`, `combine`) and
  now route through one `earnStep()`: sell a genuine surplus (never the
  stockpile), else whatever the ladder would do that is not the blocked verb,
  and only then idle.

### Lesson
- Refusing a verb early is only half the fix. The observation is unchanged
  either way, so a gate that blocks and then idles produces exactly the loop it
  was added to prevent. The turn has to be spent on the thing that unblocks the
  decision.

## [3.9.4] - 2026-09-14

### Fixed
- **`combine` was the last refusable verb without a gate**, and it became the
  dominant rejection once the others were closed — 3 of 5 in a clean window.
  The model sat on `combine {aluminum:1, carbon:1}` five times holding **45
  aluminum and zero carbon**; a soft spin that only looked varied because
  other turns interleaved with it. Ingredients are now checked against the
  inventory first and the missing input acquired instead. The recipe was
  never the problem — the cupboard was, and that has a different answer.
- **A novel mixture is a Guild filing, and the Guild charges at the moment of
  filing whatever the outcome** (`observe.guild.filing_fee`, currently 50).
  The agent was firing them on **3 credits**. A filing it cannot pay for is
  now not sent; production recipes and mixtures the world already knows are
  free and still go through untouched.

### Notes
- That closes the refusable class for `buy`, `sell`, `build` and `combine`.
  `mine` remains the one exception: it is emitted without a range check and
  recovers on the following turn.

## [3.9.3] - 2026-09-14

### Fixed
- **Guarding the ladder's sell rungs was not enough — the model picks freely.**
  3.9.1 put the restock lines off-limits inside `Ladder`, but the LLM's own
  `sell` went straight to the intent queue, and it was still liquidating the
  stockpile: `sold 20 crystal for 160` followed by `bought 13 crystal for 169`,
  a straight loss to the depot's 2× spread on the line the quartermaster was
  building. Every `sell` now passes the same protection at the last gate,
  whoever chose it: redirected onto a genuine surplus when one exists, and
  dropped entirely when one does not.

### Lesson
- A rule enforced where the decisions are *made* leaks wherever else they can
  be made. The deterministic ladder and the model are two sources; a guard on
  one is half a guard. Put it on the path they share.

## [3.9.2] - 2026-09-14

### Fixed
- **Selling in bigger lots was earning less, not more.** The depot's price
  moves against volume: dumping 100 copper took it from 4/unit to 1 and
  returned **100 credits** — exactly what 25 units would have fetched at the
  intact price, having spent 75 more copper to get it. Iron went 3 → 1 the
  same way, while metal, crystal and silicon (untouched that window) held at
  5/8/6. The 100-unit emergency lot added in 3.6.1 to fund builds faster was
  capping income at the crash price. `Ladder::DEPOT_SAFE_LOT` (25) is what the
  market absorbs without moving much; funding faster means selling on more
  turns or across more lines, not in bigger lots.

### Notes
- A second driver was found competing for the same agent: the PHPacker build
  at `bin/build/autoplay/windows/windows-x64.exe`, compiled **Sep 11** and
  running since Sep 13, driving agent 142285 concurrently with the source
  runner. It predates every fix from 3.5.0 onward, and its acts are
  interleaved with the source runner's in the world log — which is where the
  post-fix `sell metal` entries came from. Stopped. Rebuild it from current
  source before running it again.

## [3.9.1] - 2026-09-14

### Fixed
- **The agent was wash-trading its own stockpile away.** The depot runs a 2×
  spread — metal pays 5 and costs 10, crystal pays 8 and costs 16 — so a
  sell/buy round trip on one line destroys half of it. `raiseCashStep()` sells
  "the biggest depot-tradeable hoard", which was written when the biggest hoard
  was a useless glut; once the quartermaster started working, the biggest hoard
  WAS the restock line, and the agent began funding metal purchases by selling
  metal. From one live window:

      sold 100 metal for 500  →  bought 50 metal for 300   (net -50 metal)
      sold 100 metal for 100  →  bought 50 metal for 500

  The second pair is worse because dumping 100 units at once craters the price
  to 1/unit. Over that window its two largest "income" lines were **crystal
  (1,600 credits) and metal (1,200)** — 2,800 credits raised by liquidating the
  very stockpile it was building, against ~5,600 to buy back.
- New `Ladder::protectedLines()` puts the restock lines, any line an open
  colony board still wants, and the line currently being bought off the sell
  list entirely. Applied at every sell site: the quartermaster rung, the
  generic wealth rung, and the central buy/build gates. Converting a glut the
  agent has no use for into a line it needs is sound; converting a line into
  itself is a bonfire.

### Lesson
- A heuristic can be correct when written and become harmful when the state it
  assumed changes. "Sell the biggest hoard" was right while the biggest hoard
  was junk, and turned into self-cannibalism the moment the agent got good at
  stockpiling. Re-read the assumptions of a rule whenever the thing it measures
  starts moving.

## [3.9.0] - 2026-09-13

### Added
- **The agent reads the world's objective board.** `GET /expansion` - the
  endpoint behind the site's Colonies tab - carries every body, every module,
  `need`/`have`/`remaining`, each funder's `contrib` and the Accord
  conditions. New `Brain\Objectives` turns that into the only question that
  drives strategy: *where can I still do something useful, and does it take
  money or boots?*
  - `fundableWithCredits()` finds a module short only of lines the depot
    sells - fundable from anywhere with `invest{body,module,credits}`.
  - `forwardBaseTarget()` finds one that needs surface-mined exotics
    (regolith, Martian ice, perchlorate, nitrogen, acid skin, graphite) -
    the trip worth making, because no amount of credits substitutes for it.
  - Buyable/not is derived from `GameData::DEPOT_UNIT_COST`, so it cannot
    drift from the price table the buys already use.
- **`colonyDone` is now derived, not remembered.** It is recomputed from live
  `contrib` against the per-agent cap on every refresh, so a body LEAVES the
  set when a module opens up as readily as it enters when we cap out. The old
  write-only flag had Mars marked finished and skipped as a destination while
  the live board showed it at **1 of 5 modules with four lines wide open**.
- **A stuck agent asks the model, with the board in hand.** The objective
  rotation handles an ordinary rut; it cannot handle a rut it is itself part
  of. Past a full cycle of loop breaks the rotation is dropped, the objective
  board goes into the prompt, and the model is asked to pick the one thing it
  can actually contribute to. A novel stall can now resolve without a code
  change.
- The quartermaster stocks toward what an open board wants, not just its own
  build lines - so the credits and materials are banked for the moment a body
  becomes reachable.

### Fixed
- **`WorldRepository::getExpansion()` never worked.** It passed the bare
  `Endpoint::EXPANSION` string where `get()` needs a bound `Endpoint` object,
  which fails inside the HTTP layer. The method had never been called, so it
  had never shown.
- The test mock patched the HTTP client on the `NHA` object but not on the
  repositories, which capture it at construction - so any repository call in a
  test fired a LIVE request whose promise never settled.

### Lesson
- The agent had been deciding where to go from a set it wrote itself and never
  re-read. Any state the world also knows should be asked for, not
  remembered.

## [3.8.0] - 2026-09-13

A dead-end audit of the whole decision path. Four structural traps, one of
them live and the direct cause of every "stuck" report of the last two days.

### Fixed
- **`hasDepartCapableShip()` was measured against `GameData::GEAR_BODIES`** -
  `deimos, phobos, mars` - so the moment those three were colony-funded it
  returned **false for every hull, forever**. The agent could not consider
  itself flight-ready again no matter what it built, which is why it rebuilt
  the same flyer for nine hours while Venus, the one body still open, was
  never considered: Venus is not in that list. Verified live before the fix:
  `hasDepartCapableShip -> false`, `departTarget -> null`, with a working
  orbital hull on the pad. It now measures against all four destinations
  (`Ladder::liveDestinations()`).
  - Venus was excluded because `departTarget()` skips a body whose arrival
    items are missing WITHOUT recording a rejection, so it could never enter
    `$unreachable`. That risk is survivable and the exclusion was not: a
    missing `acid_skin` has a rung that crafts or buys one, and a hull that is
    merely too heavy earns a real rejection the moment it tries. Both make
    progress; the exclusion made none.
- **"Nothing worth flying to" and "this hull cannot get there" were the same
  flag.** They are now separate, because the right answer differs: a hull that
  cannot reach a body still worth reaching should be rebuilt, and a world
  where every body is funded to our cap should not be answered with more
  hulls. The second case had no branch at all and fell through to "gear a
  flyer" - a terminal state the brain had no name for.
- **The colony-done flag was write-only.** `recordColonyDone()` had a caller;
  `clearColonyDone()` had none. Colonies gain modules as the world advances
  (the Expansion decree opened Ares Base and Aphrodite Terrace mid-run), so a
  body marked done can have work for us again - and nothing could ever say so.
  Since the set only grew, reaching "all four done" was guaranteed, and that
  is permanent stranding. The Earth-side board rotation already fetches those
  boards; it now reads the answer off the fetch it was making anyway.
- **The rebuild cycle was unbounded.** A `finalize` clears every depart verdict
  so the new hull gets a fair trial - correct, but on its own it is a closed
  loop: finalize -> clear -> depart -> rejected -> stranded -> rebuild.
  `REBUILD_GENERATION_CAP` (3) puts a floor under it, and a `depart` the engine
  accepts clears the count, so the cap can never ground a working agent.

### Added
- **An endgame.** With every body funded, the agent puts credits into a colony
  module that is still short (`invest{body,module,credits}` - the engine takes
  money from anywhere now). A finished colony is worth more than another hull:
  it cuts Mars and Venus Δv by 5 world-wide and unlocks the warp-gate
  blueprint, which is the only thing that reopens the map. Rate-limited per
  board and never over a loop break, because `invest` can be refused.
- **Every refusable verb is now pre-validated or clamped at the last gate**,
  closing the spin class rather than its instances:
  - `buy` sized to the purse, and when nothing can be sold to fund it, not
    emitted at all (it used to fall through and re-fire "need 10 credits
    (have 8)" forever).
  - `sell` clamped to what is actually held.
  - `build` checked against `GameData::BUILD_COST` up front - 17 of the last
    200 live acts were "insufficient for <part>", each one a wasted turn.

### Lesson
- A refused intent changes nothing in the observation, so *any* decision the
  engine can refuse is a perfect infinite loop. The fix is not to catch them
  one at a time but to make them unexpressible.
- Check that the GOAL is reachable before debugging the pursuit of it. Six
  releases went into behaviour while the capability test at the centre of it
  could only ever answer "no".

## [3.7.1] - 2026-09-13

### Fixed
- **`quartermaster` could never engage.** Its "a finished ship outranks a thin
  cupboard" guard asked `Ladder::hasOrbitalShip()` — and the agent owns **48**
  orbital hulls, not one of which can reach the only body still on the table.
  The guard answered "flight-ready, carry on" every single turn. It now takes
  the answer from `AutoPlayer`, which knows which bodies are still worth going
  to, so the test is a USABLE ship rather than merely owning one. The stance
  pick moved below `$shipStranded` to have that answer available.

### Lesson
- "Do we have a ship?" and "can we get anywhere?" are different questions, and
  a hoarder's inventory is exactly where they diverge.

## [3.7.0] - 2026-09-13

### Added
- **A resupply chain, so running dry stops being a slow death.** Two new
  stances run as a sequence before the mission resumes:

      quartermaster --restocked--> researcher --grants dry up--> expansionist

  - **`quartermaster`** takes the turn when `metal` or `crystal` falls under
    `RESTOCK_ENTER` (60) - roughly a handful of parts. Supply is then the only
    job: work the deposit underfoot (free material beats the depot), sell a
    real lot of whatever glut is on hand, and buy the two blocked lines in
    quantity. It does not gear, ride or depart while short.
  - **`researcher`** takes over once every line is back to `RESTOCK_EXIT`
    (250, the mining baseline). The surplus just paid for is spent on
    speculative `combine`s while it is there - the drive recipe is still
    undocumented - and it holds only while the grants keep coming AND there
    is stock deep enough to cut one from.
  - Then the mission resumes on its own.
- Entry and exit marks differ on purpose: a single threshold would hand back
  to the mission with a cupboard that is bare again one build later, which is
  the sell / buy / build / broke cycle this exists to end.

### Notes
- **Two things outrank restocking.** A finished orbital hull on the pad -
  never ground a ready ship through an open window over a metal count - and
  being anywhere but Earth's ground, where there is nothing to restock from
  and "do not fly while short" would strand the agent.
- The anti-vanity-spire guard now covers all three grounded stances. It was
  gated on `expansionist` alone, so the model could raise towers freely the
  moment the resupply chain took over.

## [3.6.2] - 2026-09-13

### Fixed
- **The rebuild was aiming at a ship that could not reach the only
  destination left.** `SHIP_BUNDLE_TARGET` (3 engines, 2 propellers, 3 wings)
  masses **856** and fails Venus's thrust-to-weight gate. With deimos, phobos
  and mars all colony-done, Venus is the only body still on the table - so
  `hasDepartCapableShip()` was false no matter how perfectly the agent built
  the bundle. It finished one, found itself still stranded, and started over.
  Forever. That, not any single looping verb, is what "stuck building a ship"
  actually was.
- Retargeted to **2 engines, 3 propellers, 2 wings** (same 14 parts, and
  cheaper - propellers are bare metal while engines need an `engine` item).
  Propellers multiply engine power into thrust, so the lighter mix keeps the
  full 2,800 thrust at **721** mass and clears every gate including Venus,
  while keeping both fuel tanks - 600 capacity against the 375 units the
  crossing needs. Venus-capable on thrust but not on tankage is still
  stranded, so a test now pins both halves.

### Lesson
- Check that the GOAL is reachable before debugging the pursuit of it. Six
  releases went into the agent's behaviour while the target it was building
  toward could not have worked; the engine's own `assess()` had the answer
  all along and was never asked.

## [3.6.1] - 2026-09-13

### Fixed
- **Nine hours of rebuilding the same flyer.** Two causes, both live-traced
  through `GET /log?agent=`:
  - **A premature `finalize` threw the parts away.** The model reads a pile
    of loose parts, calls the bundle "nearly complete" and finalizes - which
    SPENDS them on a flyer with no orbital engine (live: vehicle #148875,
    `flies=True`, no jet). That hull cannot depart, so the agent stays
    stranded and the rebuild starts over on an empty pile. A `finalize` is
    now held until `Ladder::flyerReady()` says the bundle actually assesses
    as depart-capable.
  - **The income path was too slow to ever finish one.** A flyer part costs
    8-10 metal (~100 credits) and the sell rung moved a flat **20 units** a
    turn - 20 iron fetches 60 credits. So the agent alternated sell-a-lot /
    buy-some-metal / build-one-part / broke-again indefinitely, against a
    stockpile of 2,800 iron and half a million regolith. When the credits are
    genuinely needed the lot is now 100; shedding a hoard stays at 20.

### Lesson
- "Stuck" is not always a loop. The verbs here were varied and every one of
  them applied - the agent was making real progress and then destroying it
  each time the model finalized early. Check whether progress is being LOST,
  not just whether the same call repeats.

## [3.6.0] - 2026-09-13

### Changed
- **Mining now works a deposit properly instead of topping up and wandering
  off.** A deposit is a place and walking to one costs turns, so the point of
  going is to come back full. Two new marks give the behaviour hysteresis:
  - `Ladder::MINE_STOCK_TARGET` (1,000) - the high-water mark. Standing on a
    deposit, the agent keeps working it to here rather than to the thin
    `RESOURCE_TARGET` (30). At ~15 units a turn that is roughly 60 turns of
    committed work.
  - `Ladder::MINE_RESEEK_FLOOR` (250) - the low-water mark. It only goes
    LOOKING for a deposit again once a resource has fallen this far, not the
    moment it dips below a craft floor. The gap between the two marks is what
    stops it oscillating between "top up" and "do something else".
- The sell rung no longer fights the band. `capFor()` used to shed anything
  over `HOARD_CAP` (80), which would have read a freshly mined 1,000 as a
  hoard and sold it straight back down; the cap now sits above the stockpile
  the band is deliberately building. A genuine credit emergency still sells,
  but keeps the baseline rather than a token 10 - selling under it only
  triggers a re-seek and spends the next hour re-mining what was just sold.
  A pile already under the baseline is still fair game when the alternative
  is being broke.

### Notes
- The band sits below every rung that matters (combat, the flight kit, a
  colony board), so it fills idle time rather than competing with the
  mission.

## [3.5.6] - 2026-09-13

### Added
- **Every `buy` is now priced against the purse at the last gate**, after
  every override has had its say, whatever rung produced it. `n` is clamped
  to what the credits actually cover, and when not even one unit is
  affordable the decision becomes a `sell` of the biggest tradeable glut -
  the only move that changes the inputs.

### Fixed
- The fresh-flyer frame rung was ordering `buy {metal, n:20}` (200 credits)
  on a 68-credit purse: *"need 200 credits (have 68)"*, **477 times in a
  row**. This is the THIRD rung caught doing exactly this - 3.5.0
  (`cryo_fuel n:30`, 419 refusals, treasury 7,183 → 158) and 3.5.1 (`metal
  n:20` in the craft-spin breaker) were the first two. Hence the central
  gate above rather than a fourth per-rung patch.

### Lesson
- When the same defect appears in a third place, stop fixing instances and
  move the check to the boundary they all pass through. A refused intent
  changes nothing in the observation, which makes "order something you
  cannot afford" a perfect infinite loop - it must be impossible to express,
  not merely absent from the rungs audited so far.

## [3.5.5] - 2026-09-13

### Fixed
- **The agent was holding in orbit for a departure it could never make.**
  `Ladder::DEPART_FUEL_MIN` (90 units) is sized for the MOONS (Δv 50-55) -
  but both moons are on the skip list once our colony share is spent, so the
  only live destinations are Mars (Δv 100) and Venus (Δv 130). At 90 units
  the brain called itself "flight-ready", held for a window, departed, and
  collected *"Δv too low: your ship makes 62 but Venus needs 130"* - **47
  times in one evening, sitting on 6,320 unspent credits.**
- New `Ladder::fuelTargetFromRejection()` solves the real bar from the
  engine's own curve. A Δv rejection is a solved point on
  `dv = 900·L / (mass + 5·L)`, so the ship's mass falls out
  (`mass = 900·L/dv - 5·L`) and the load that clears the destination follows
  (`L' = need·mass / (900 - 5·need)`, +5% margin): **~238 units for Mars,
  ~494 for Venus** on this hull, against the 90 it was stopping at. The goal
  is captured once, at the rejection (the derivation needs the fuel load from
  that moment), stored per agent, and spent against by the hold's buy rung.
- Deriving mass from the rejection rather than the vehicle list is
  deliberate: `GET /observe`'s vehicles carry no `mass` field, and it also
  sidesteps guessing which of the agent's **75** hulls the engine picked.
- A destination needing Δv 180+ zeroes the denominator - unreachable at any
  load - and returns `null` rather than sending the agent shopping for
  infinite fuel. That case wants a lighter ship, not a bigger tank.

### Lesson
- A threshold constant tuned for one era silently becomes a trap in the next.
  `DEPART_FUEL_MIN` was right when the moons were the target and wrong the
  moment they were finished; nothing failed loudly, the agent just waited.
  Prefer a bar DERIVED from what the engine says it wants over a number that
  was correct once.

## [3.5.4] - 2026-09-12

### Fixed
- **Dock range is 2, not 8** - corrected live: 3.5.3 widened it after seeing a
  dock succeed at an observed dist of 6, but the engine rejects with *"nearest
  asteroid is out of dock range (2)"*. The earlier success was the asteroid
  DRIFTING into range, not a looser rule. `Ladder::DOCK_RANGE` now records the
  engine's own number instead of a guess.
- Because they drift (#2953 read 6, then 8, and was dockable in between),
  "out of range" is a moving target rather than a permanent no - so a hold
  with an asteroid within one `move` (`Ladder::MOVE_RANGE`, 6, from the
  engine's "drove on carbon, range 6") now closes on it instead of idling.
  `move` is applied in space, so the intercept is legal.

### Lesson
- One success is not a rule. A single dock at dist 6 looked like evidence the
  range was wider; it was a moving object. Prefer the engine's rejection text
  - it states the constraint outright - over an inference from one sample.

## [3.5.3] - 2026-09-12

### Fixed
- **3.5.2's docked-asteroid check was placed where it could never run.** It
  sat in an `elseif` after the branch that accepts `Ladder::suggestion()`'s
  hold step - and that step bottoms out in `Ladder::noop()`, a `deposit`,
  which is a perfectly valid non-`land`/`depart` verb. So the first branch
  always won and the hold went right on idling. The check now runs first and
  short-circuits the suggestion entirely.
- **The hold's own `dock` rung had never fired.** It required an asteroid
  within 2 cells; the live engine accepted a dock at **dist 6** (intent
  #3415083), and the nearest asteroid in orbit sits at 6. Widened to 8 and
  the test now pins 2/4/6/8 as dockable rather than encoding the guess.

### Lesson
- Placing a new guard *after* an existing branch that ends in a permissive
  fallback is the same as not adding it. Check what the branch above
  actually returns in the failing case before choosing where to insert.

## [3.5.2] - 2026-09-12

### Fixed
- **The orbital hold burned every turn on a no-op.** With the rebuilt flyer
  finally waiting in orbit for a transfer window, the hold branch fell
  through to `AutoPlayer::idle()`, which the engine answers with *"stashed 1
  acid (self-scoped no-op - your balance is unchanged)"* - ~90 ticks of
  nothing while credits sat at 8.
- The root cause is that **`GET /observe` carries no `docked` key at all**,
  so both of `Ladder`'s `$raw['docked']` mining rungs are unreachable dead
  code and a docked asteroid was invisible to the brain. The only report of
  the state is the `dock` intent's own result text, so new
  `AutoPlayer::dockedToAsteroid()` reads it back off the world profile's
  recent-intent list: an applied `dock` with nothing relocating the agent
  after it means we are still attached. The hold now mines that asteroid.
- The hold's pass-through verb list admitted `deposit`/`wait` and excluded
  `mine`/`sell`, which is backwards - it protected the no-op and overrode
  the productive moves. Swapped.

### Lesson
- A rung keyed on an observation field that does not exist never fires and
  never errors; it just quietly degrades to the fallback. When a
  deterministic branch seems not to run, check the key is actually in the
  payload before reading the logic.

## [3.5.1] - 2026-09-12

### Fixed
- **The metal spin.** The craft-spin breaker in `AutoPlayer::step()` — the
  rung that stops a drifted upgrade recipe from wedging the flyer rebuild —
  ordered a flat `buy {metal, n:20}`. Metal is 10/unit, so that order costs
  200 credits; with 110 in the purse the engine refused it, nothing in the
  observation changed, and the identical buy re-fired every turn forever.
  Exactly the shape of the 3.5.0 `cryo_fuel` bankruptcy, in the one buy that
  had not been routed through `Ladder::affordableBuy()`. It now is.
- When even a single unit is out of reach, the rung no longer repeats a buy
  it cannot make: new `Ladder::raiseCashStep()` sells the biggest
  depot-tradeable hoard instead, which is the only move that changes the
  inputs to the decision. Untradeable gluts (`brine`) are skipped, so the
  rung cannot wedge on them.

### Lesson
- Every hardcoded `buy` `n` is a latent spin. A quantity the purse cannot
  cover is not a smaller purchase — it is a refused intent with no state
  change, which is indistinguishable from a no-op loop. Price the order, and
  always give a buy rung a not-broke fall-through.

## [3.5.0] - 2026-09-12

### Added
- **The agent now asks the other agents for help.** This world is played by
  other LLM agents, and they read world chat — so when a colony we have
  funded to our per-agent cap is still short, `Ladder::colonyCallForHelp()`
  broadcasts a `say` naming the body, the module, the exact outstanding
  lines and BOTH verbs that close them (`construct {shape:colony,…}` for a
  hauler on the surface, `invest {body,module,credits}` for anyone with
  spare credits anywhere), plus the payoff — finishing a moon cuts Mars and
  Venus Δv by 5 world-wide and unlocks the warp-gate blueprint. It fires
  only for lines we are genuinely capped on (while we still have headroom,
  funding beats asking), only from Earth (at a body the agent is mid-mission
  and a `say` fights the return state machine), and at most once per body
  per `COLONY_CALL_COOLDOWN_TICKS` (1800, ~1h) so it reads as a standing
  request rather than chatter.
- **`invest` can now fund a COLONY module from anywhere** — `VerbsTrait::invest()`
  takes an optional `$body`, per the engine's new `invest{body,module,credits}`.
  No need to be standing on the body; only the fixed-price industrial lines
  can be bought this way, every exotic line still has to be mined on the
  surface. (This is why the Forward Bases suddenly moved.)
- `GameData::DEPOT_UNIT_COST` — the real per-unit buy price for all 37
  tradeable lines, transcribed from the live `GET /depot`.

### Fixed
- **`fallbackDecision()` silently dropped the depart skip-list — the missing
  path behind the 3.4.11–3.4.13 chase.** It called `Ladder::suggestion()`
  without the 6th argument, so the ladder ran with an EMPTY
  `$departUnreachable` and happily re-proposed a permanently TWR-rejected
  body or one whose colony share is already funded. Because all five callers
  re-assign the decision AFTER the outbound-depart sanity-check and the
  dead-end-hull override have run, nothing revalidated it — which is exactly
  why tracing those paths never explained it. Live cost in one three-hour
  window: **21 departs, 19 of them to a Venus that rejects every one** for
  the thrust-to-weight shortfall that put it in `$departUnreachable` to begin
  with, and 2 back to an already-funded Deimos. All five call sites now pass
  `$departSelectSkip`.
- **The buy rungs bankrupted the agent.** They gated on a flat
  `$credits >= 60` and then ordered a fixed `n:30`. `cryo_fuel` is 16/unit,
  so that order costs **480**: with 158 credits the agent cleared the guard,
  the engine refused the order, nothing changed, and the identical buy
  re-fired **419 times in three hours** — after the ~15 that did land drained
  the treasury from ~7,000 credits to 158. New `Ladder::affordableBuy()`
  sizes `n` to `credits / unit_cost` and returns null when even one unit is
  out of reach, so the ladder falls through to earning instead of spinning.
- The "on Earth's ground with a ship → force toward orbit" guardrail also
  used the bare `$departUnreachable`; with every body unreachable-or-done
  there is nowhere worth climbing to, and forcing the agent at the elevator
  anyway is what kept it flying round trips it could not profit from. Now on
  `$departSelectSkip`, and it (like the holding and dead-end-hull overrides)
  leaves a `say` alone.

## [3.4.13] - 2026-09-11

### Fixed
- **`Ladder::stanceMove()`'s inner depart check dropped the unreachable list.**
  `if (($dest = self::departTarget($raw)) !== null)` called `departTarget()`
  with no second argument at all, silently defaulting to an empty skip list
  — every other call to `departTarget()` in the file passes its
  `$departUnreachable`/`$departSelectSkip`; this was the one that didn't.
  Fixed to pass it through.

### Known issue — still under observation
- Live on agent 142285: once, with Deimos's window legitimately open, the
  agent departed for Deimos despite it being in `colonyDoneBodies()` — the
  dedup [3.4.9] added should have kept it heading toward Mars/Venus instead.
  Traced every place a `depart` decision can be finalized
  (`AutoPlayer::step()`'s outbound sanity-check, the two `$departNow`-forced
  blocks, the [3.4.12] dead-end-hull override) and each one demonstrably
  only ever targets `$departServiceable`/`$departServiceable`-gated
  destinations, which are built from the correctly-merged skip list — so on
  paper this shouldn't be reachable. Not yet reproduced against a live open
  Deimos/Phobos/Mars window with debug instrumentation (windows closed for
  ~580 ticks at investigation time). Revisiting an already-funded body isn't
  resource-destructive (no repeated fuel burn, unlike the bugs this session
  fixed) — just a missed opportunity to spend an open window on Mars/Venus
  instead — so shipping the rest of this release rather than blocking on it.

## [3.4.12] - 2026-09-11

### Fixed
- **[3.4.11]'s stranded-hull override was a no-op in practice.** It rewrote
  the decision but then handed off to `Ladder::suggestion()`, which runs its
  OWN internal `hasDepartCapableShip()` checks against whatever unreachable
  list it's given — and it was still being passed the bare
  `$departUnreachable` (deimos/phobos were never *rejected*, so they weren't
  in it), so the ladder itself still thought the hull was fine and held for
  a window on it, undoing [3.4.11]'s fix one call deeper. Reproduced live on
  agent 142285 immediately after redeploying 3.4.11: `> dead-end hull —
  expansionist — flight-ready ship in orbit; holding for a transfer window
  to open` — the override *had* fired (the "dead-end hull —" prefix proves
  it), it just handed off to a ladder call that disagreed. Now passes
  `$departSelectSkip` (the same `$departUnreachable ∪ colonyDoneBodies()`
  list [3.4.11] added) into that `Ladder::suggestion()` call too. New
  regression test reproduces the exact live shape (in Earth orbit, not on
  the ground, mars/venus unreachable, deimos/phobos done, an open Venus
  window it still can't take) and asserts against the model AND the ladder
  both agreeing to hold — confirmed to fail without this fix, pass with it.

## [3.4.11] - 2026-09-11

### Fixed
- **A hull with nowhere useful left to go was reported "capable" forever.**
  `Ladder::hasDepartCapableShip()` only checks deimos/phobos/mars
  (`GameData::GEAR_BODIES`) against `$departUnreachable` — a body whose
  colony this agent already funded (`StateStore::colonyDoneBodies()`, added
  in [3.4.9]) never enters that set. Once Venus could actually be attempted
  ([3.4.9]'s `acid_skin` chain) and got a genuine TWR rejection alongside
  Mars, deimos and phobos being merely *done* (not unreachable) still made
  the hull look "capable" — the ship held in Earth orbit forever with
  nowhere left to profitably go, including through an open Venus window it
  could never take. `AutoPlayer::step()`'s stranded-hull check now folds
  `colonyDoneBodies()` into the skip list it passes `hasDepartCapableShip()`
  (reusing `$departSelectSkip`, already used for destination *selection*),
  so "every gear body is rejected-or-already-done" drives the same
  gear-a-fresh-flyer rebuild a genuine dead end does. Found live on agent
  142285 right after [3.4.10]'s ride-cooldown fix confirmed it was no longer
  thrashing the elevator — this was the next layer down.

## [3.4.10] - 2026-09-11

### Fixed
- **The elevator station-keep bounce (v3.2.35) was firing on almost every
  autoplay turn instead of the rare reset it was designed for.** Live near
  Earth, orbital decay crossed the 300 depart floor within a single ~15s
  autoplay turn, so `ride` down / `ride` up fired back to back for 9+
  straight minutes (35 consecutive `ride`s, no other action) — the ship
  never spent a turn actually holding (topping cryo_fuel, packing
  `heat_shield`/`acid_skin`, mining) and was rarely sitting in the depart
  band long enough for an opening transfer window to catch it there. New
  `StateStore::recordRide()` / `rideCooldownActive()` (12-tick cooldown,
  mirroring the existing `depart` retry cooldown): `AutoPlayer::step()`
  rewrites a `ride` arriving before its cooldown to a hold, and arms the
  cooldown on every `ride` that goes through. Found live on agent 142285
  after [3.4.9] got it to Phobos and Deimos both — the colony-done fix is
  working; this is the next layer down.

## [3.4.9] - 2026-09-11

### Added
- **The mission can now actually reach every body — Deimos, Phobos, Mars,
  *and* Venus — instead of only ever revisiting the first one it could reach.**
  Two gaps closed:
  - **`acid_skin` was never craftable.** Venus needs `heat_shield` *and*
    `acid_skin` on arrival; only `heat_shield` was ever packed, so
    `Ladder::departTarget()` silently skipped Venus forever regardless of its
    window. New `Ladder::acidSkinStep()` walks the real chain (verified live
    against `GET /rules`): `acid_skin = acid + rubber`, `rubber = sulfur +
    plastic`, `plastic = oil + carbon`, `acid = sulfur + water` — four
    Earth-depot raws, three sequential combines, one step per call. Wired in
    alongside the existing `heat_shield` packing (Earth gear-up + holding for
    a window), so a fully-geared ship now carries both Mars and Venus items.
  - **A funded colony's body was never excluded from future `depart` picks.**
    `DEPART_ORDER` is fixed cheapest-first (deimos, phobos, mars, venus) with
    no notion of "already done here", so once Deimos was reachable the agent
    would return to it forever rather than move on. New
    `StateStore::recordColonyDone()` / `colonyDoneBodies()` / `clearColonyDone()`:
    recorded the moment this agent's colony share on a body is funded, and
    merged into `departTarget()`'s skip list for destination *selection* only
    (kept separate from `$departUnreachable`, which also feeds
    `hasDepartCapableShip()`'s dead-hull check and must stay a pure TWR/gear
    fact) — so the next open window sends it to a body that still has work.

`GameData::CRAFT_INPUTS` gained `plastic` / `rubber` / `acid` / `acid_skin`
entries documenting the chain. Full suite green (278 tests, 869 assertions);
php-cs-fixer clean.

## [3.4.8] - 2026-09-11

### Fixed
- **The 3.4.7 return state machine sawtoothed at the elevator top.** On a moon,
  the ground altitude drifts (~300-500) rather than sitting at 0, and
  `expansion.place.where` stays `"body_surface"` even after riding up to the
  elevator top (alt ~600) — so the guardrail read "grounded, window open" at
  the TOP too and rode straight back down, then back up, forever. It now
  disambiguates "on the ground" vs "up in the depart band" by altitude
  (`alt < 550`, not just `place.where`), and in the band it only ever `depart`s
  or **holds** — it never rides back down.
- **The return state machine went dormant whenever `expansion.at_body` /
  `at_body_orbit` / `location` all glitched empty for a tick** (the same field
  flakiness 3.4.7 fixed for `departTarget()`), letting the agent drift back
  into model/ladder churn on exactly those ticks. New persistent
  `StateStore::setGoingHome()` / `goingHome()` / `clearGoingHome()` latch: set
  once the colony share is funded at a body, read every turn instead of
  re-deriving "am I heading home" from the flaky per-tick fields, and cleared
  only on a positive home signal (`location` is Earth, or in transit to it).

## [3.4.7] - 2026-09-11

### Fixed
- **Burned ~80 cryo_fuel over 4 hours `depart`-ing to the moon it was already
  on.** When `expansion.at_body` / `at_body_orbit` momentarily glitched to
  empty while the agent sat on Deimos, `Ladder::departTarget()` read it as
  Earth orbit and — Deimos's window being open — offered **`deimos`** as a
  serviceable target. The model / a loop-break then fired `depart {dest:
  'deimos'}`, which burns fuel and goes nowhere, on repeat (fuel 101 → 19).
  `departTarget()` now also bails on `onBodySurface()` / a non-Earth
  `expansion.location`, and the colony guardrail's "share funded" branch is a
  strict state machine — the ONLY `depart` it can emit is `{dest:'earth'}`
  from a body's orbit with fuel and an open window; otherwise it stocks
  cryo_fuel to `return_dv + 15`, rides the tall elevator near the window, or
  idles. It no longer defers to `Ladder::suggestion()` (which returned
  dock/mine churn) or lets a model `depart` through.

## [3.4.6] - 2026-09-10

### Fixed
- **3.4.5 rode up ~400 ticks early and idle-decayed out of the band.** The
  return window was `opens_in: 416` and the guardrail sent the agent straight
  up the elevator to `deposit`-idle in orbit, fighting orbital decay the whole
  time. It now only rides up when the window is **open or `opens_in ≤ 40`**;
  while it is further out the guardrail just kills the doomed body `construct`s
  and lets the model / ladder do productive surface work (mine the body's
  resources, research) until it is time to go.

## [3.4.5] - 2026-09-10

### Fixed
- **The "head home" hand-off only triggered on a `construct` pick.** Once the
  Deimos colony share was funded and the transfer window shut for ~400 ticks,
  the model picked `mine` / `dock` / `sell` and the guardrail — which only
  fired on a body `construct` — let it churn. It now fires on **any** pick once
  the colony share is funded (except an already-sane `ride` / `depart` /
  `land`): from the body's orbit with a flyer + fuel + open window →
  `depart {dest:'earth'}`; on the surface with a flyer → walk to the tall
  elevator and `ride` up to hold in the depart band for the window; no flyer
  yet → gear one; otherwise idle and wait.

## [3.4.4] - 2026-09-10

### Fixed
- **The brain thought it was in orbit while standing on Deimos.** On a MOON the
  engine reports `in_space:true` and a non-zero `altitude` even on the ground,
  so every `!in_space && altitude === 0` check read the agent as airborne: the
  gear-up-a-flyer rung never fired, the colony guardrail's surface branch
  never fired, and it churned. New `Ladder::onBodySurface()` keys off
  `expansion.place.where === "body_surface"` / `location: "on_<body>"` /
  `at_body` (never `at_body_orbit`); `Ladder::stanceMove` and the `AutoPlayer`
  colony guardrail use it.
- **Stranded on a body with no way back.** A standing-income extractor is now
  only built once the agent already has a ship (`hasShipEarly` gate), so a
  shipless agent on a body gears a flyer instead. And there was **no return
  leg at all** — every `depart` path was Earth→body. The colony guardrail now,
  once this agent's colony share is funded and it is in the body's orbit with
  a flight-ready fuelled ship and the window open, emits `depart
  {dest:'earth'}`; the `at_body_orbit` land-force and the outbound-`depart`
  sanity-check both exempt it.

## [3.4.3] - 2026-09-10

### Fixed
- **3.4.2's "move on" hand-off never fired — the agent had ridden UP.** It was
  gated on `altitude === 0`, but by the time its Deimos colony share was
  funded it had ridden the elevator to orbit (alt 480) and was spamming
  `construct {shape:colony, module:habitat}` from up there. The colony board is
  now fetched whenever `Ladder::atBody()` is set (surface **or** orbit), and
  the guardrail hands off to the flight ladder for any doomed body `construct`
  from a body's orbit once this agent's colony share is funded — so it starts
  the trip home instead of churning in orbit.

## [3.4.2] - 2026-09-10

### Added
- **`Ladder::acquire()` — get a needed resource by WHERE it is**, not always by
  buying: a matching `nearby_deposits` entry underfoot → `mine` / `chop` /
  `gather`; one within ~30 tiles → `move` to it; depot-tradeable + credits →
  `buy`; an asteroid metal + a docked / adjacent rock → `mine` / `dock`;
  craftable with an input on hand → `combine`; `null` when it can't be got
  here. `colonyFundStep()` now routes its acquire branch through it (so the
  agent mines free `iron` / `crystal` on Deimos instead of buying it) and
  takes the observation as a new optional `$raw` argument.

### Fixed
- **Stuck churning on a body after its colony work was done.** Once the agent
  had funded its full per-agent share of the last Deimos module it fell into a
  `mine ↔ sell` loop and spammed `construct {shape:colony, module:…}` with
  hallucinated module names (`habitat`, `power_grid`, `communications`, …) that
  the engine rejected every tick. The colony guardrail now treats a
  `shape:colony` construct whose `module` is not an open module on the board
  (and a personal extractor past the cap) as dead: it hands off to the
  expansionist flight ladder — station-keep, ride the elevator, depart for
  home / the next body — instead of re-issuing the doomed `construct`.

## [3.4.1] - 2026-09-10

### Fixed
- **3.4.0's colony-fund `construct {shape:colony}` was clobbered one line
  later.** The materials-blocked-construct fixer (3.3.2) saw the feed's stale
  `"kind must be one buildable on Deimos: cregolith_cracker"` rejections and
  its `elseif` rewrote *any* body-surface `construct` without a `kind` — the
  colony fund call included — back to `construct {shape:extractor,
  kind:cregolith_cracker}`. Verified live: the first turn funded
  (`buy superalloy`), every turn after went back to spamming extractors. That
  fixer now skips a `shape:colony` construct **when a real colony board with an
  open module backs it** (`Ladder::colonyNextModule() !== null`); a bare
  `shape:colony` with no board is still the model's spin and still gets fixed.

## [3.4.0] - 2026-09-10

### Added
- **Colony-board awareness.** Standing on a body, `AutoPlayer` now fetches
  `GET /colony/{body}` and folds it into the decision:
  - `Ladder::colonyNextModule()` — the first still-incomplete module;
  - `Ladder::colonyAgentHeadroom()` — `min(remaining, floor(need × cap%) −
    own contribution)` per outstanding material, honouring the board's
    `cap_pct_per_agent`;
  - `Ladder::colonyFundStep()` — hold a needed material → `construct
    {shape:colony, body, module}` (which consumes it), else **buy** the
    material this agent has the most headroom on;
  - `Ladder::ownedExtractors()` + `MAX_BODY_EXTRACTORS` (3) — once the colony
    is done (or this agent has capped its share) and it already runs enough
    personal extractors, a further `construct {shape:extractor}` is dropped for
    an earning / holding move.
  Fixes the live Deimos failure: after helping finish 3 of the 4 "Forward
  Base" modules the agent raised **12 redundant `cregolith_cracker`
  extractors** while the Mass Driver sat at 1/160 superalloy — because
  `expansion.colony` is never in the observation and the ladder fell back to
  "extractor for income" forever.

## [3.3.3] - 2026-09-10

### Fixed
- **The 3.3.2 body-surface guardrail's rewrite was immediately reverted.** It
  correctly swapped the doomed Deimos `construct` for `buy metal` / a chip
  `combine` — then the later "on the ground with a finished ship → get to
  orbit and depart" force ran, saw `altitude 0` + a depart-capable ship, and
  overwrote the decision with `Ladder::suggestion()`, which on a body surface
  returns the same `construct extractor` (verified live: the guardrail logged
  the right rewrite every turn while `construct kind:mine` still went out).
  That Earth-gearing force is now gated to `Ladder::atBody($raw) === null` — it
  never fires on a destination body, where the job is the colony, not a hop
  home. Also: `bodyBuildStep` needs credits and `AgentObservation::getInventory()`
  doesn't always carry them, so the guardrail folds `credits` in from the
  observation before calling it.

## [3.3.2] - 2026-09-10

### Fixed
- **Deimos body-surface guardrail (3.3.1) only looked at the single newest
  `construct` rejection and missed.** The model cycles three bad shapes on the
  surface (`shape:colony/module:…` → "module must be one of…", `kind:mine` →
  "kind must be one buildable on Deimos: cregolith_cracker", and the real
  `kind:cregolith_cracker` → "insufficient … need {…}"), so the newest feed
  entry was usually not the materials one and the guardrail no-oped. It now
  scans **every** recent `construct` rejection, taking the engine's named
  buildable `kind` for the body from one and a `need {…}` shortfall from
  another. Short on materials → `Ladder::bodyBuildStep` (buy / combine); else
  it submits the exact `{shape:extractor, kind:<named>, body:<body>}` the
  engine asked for, dropping the model's bad `shape`/`module`.

## [3.3.1] - 2026-09-10

### Fixed
- **Stuck on the Deimos surface re-issuing a colony `construct` it could not
  afford.** The agent landed on Deimos with 25k credits and then spun forever
  on `construct` of the `cregolith_cracker` module — the engine refused it
  every tick with `insufficient … (need {'metal': 80, 'chip': 10})` (it held
  metal 33, chip 0) and nothing routed the credits toward the materials; the
  loop-guard's objective rotation just produced the same `construct`. New
  `Ladder::parseNeed()` pulls the `resource => qty` map out of that rejection
  and `Ladder::bodyBuildStep()` returns the next acquisition step (buy the
  depot raw it is short on, or `combine` a `chip` from silicon + a conductor,
  crafting the whole shortfall in one `n`-batch). `AutoPlayer` reads the
  newest `construct` rejection off the world feed and, for a body-surface
  project (`shape` colony / terraform / extractor / station, or any args with
  `body`), swaps the doomed `construct` for that step — or, on a "kind must be
  one buildable on X: Y" rejection, rewrites the bad `kind` arg in place.

## [3.3.0] - 2026-09-10

### Added
- **Capability ledger — the brain now learns from the world's own activity
  feed.** Every turn `AutoPlayer` pulls `GET /agent/{id}` and folds each *new*
  rejected act from its `recent` feed through the new
  `NHA\Brain\RejectionClassifier`, which drops transient reasons (closed
  window, a one-turn shortage) and classes the durable ones onto a per-agent
  ledger (`NHA\State\CapabilityLedgerTrait` on `StateStore`):
  - `needs_part` / `capability` — a hull limit (`landing_gear`,
    thrust-to-weight); cleared the moment a new `finalize` is committed.
  - `needs_item` — a missing arrival consumable (`acid_skin`, `heat_shield`);
    cleared as soon as the item is seen in inventory.
  - `needs_enum` — a bad enum argument (`construct` module, unknown `build`
    part); sticky for the run.
  A `depart:<body>` verdict merges into `departUnreachable`, so the flight
  guardrails stop *holding for* — and stop retrying — a hop the current ship
  cannot make, even when the refusal only ever appeared on the feed and never
  came back through an intent poll. The whole ledger is surfaced to the LLM as
  `blocked_capabilities` in the turn context.
- `docs/PLAYBOOK.md` — maintained UML (Mermaid) for the autoplay brain: the
  per-turn flow of `AutoPlayer::step()`, the `AgentBrain::suggestion()` ladder,
  the loop guard, and a component diagram. Update it alongside any change to
  that logic.

## [3.2.46] - 2026-09-10

### Fixed
- **Kept re-issuing `land_body` after it had already landed.** The arrival fix
  keyed the "land now" force off `Ladder::atBody()`, which stays non-null once
  ON the surface too, so a landed agent spammed a rejected `land_body`. New
  `Ladder::atBodyOrbit()` is the body only while still in orbit (`at_body`
  unset); ladder + `AutoPlayer` use it, so the force stops the moment `at_body`
  is set.

## [3.2.45] - 2026-09-10

### Fixed
- **It flew to Deimos and then parked in its orbit doing nothing.** Arrival sets
  `expansion.at_body_orbit` / `location: "orbit_deimos"` — `at_body` stays null
  until you `land_body`. Every gate checked only `at_body`, so the agent read
  itself as still holding in Earth orbit and `deposit`-ed. New
  `Ladder::atBody()` resolves surface *or* orbit arrival; `isHoldingForWindow()`
  / `departTarget()` / `stanceMove` treat it as "not in Earth orbit", and both
  the ladder and `AutoPlayer` force `land_body` (or `land_moon`) from a body's
  orbit.

## [3.2.44] - 2026-09-10

### Fixed
- **The agent HAD departed — it just didn't know it.** A successful `depart`
  puts the agent on the interplanetary crossing: `expansion.transit = {to,
  eta_tick}`, `altitude = 600`, `at_body` still unset. Every "in Earth orbit,
  hold / depart" gate only checked `at_body`, so the agent read itself as
  parked-and-waiting, kept re-issuing `depart deimos` (rejected "already in
  transit to Deimos"), and fell back to `deposit`. New `Ladder::inTransit()`
  short-circuits `isHoldingForWindow()`, `departTarget()` and the expansionist
  `stanceMove` (→ idle "en route to … ETA n"); `AutoPlayer` suppresses
  loop-break and forces the no-op until arrival sets `at_body`.

### Changed
- The `[autoplay]` status line now appends last turn's intent outcome when it
  was **rejected** (`⤷ last turn's \`depart\` was rejected: already in transit
  to Deimos`), so a silently-dropped intent is visible.

## [3.2.43] - 2026-09-10

### Fixed
- **`finalize` now clears the depart verdicts at decision time.** The clear ran
  only when the finalize's *outcome* was polled and it was the last decision —
  a double-`finalize` (the second rejected "no loose parts") or any decision in
  between lost it, so a freshly-built geared hull kept inheriting
  `agent_depart_unreachable` and the agent rebuilt forever. Wiped the moment a
  `finalize` is committed instead; if it fails the verdicts re-learn on the
  next `depart` anyway.

## [3.2.42] - 2026-09-10

### Fixed
- **`bearing` is unobtainable — stop chasing it.** The propeller upgrade
  `bearing` can't be made (`combine metal+oil` mints superalloy) or bought
  ("depot doesn't trade bearing"), so 3.2.40/3.2.41's buy path just spun on
  rejections until the engine's own "loop detected" kicked in. `propeller` is
  removed from `SHIP_PART_UPGRADE` and the bearing step from
  `Ladder::shipCraftStep()`; propellers build bare. A bare-propeller flyer
  still flies (thrust 2800 / mass 880) and clears the deimos/phobos/mars
  `depart` gates — `GameData::assess()` confirms.

## [3.2.41] - 2026-09-10

### Fixed
- **The stranded-rebuild override now also replaces a model `combine`.** 3.2.39
  exempted `combine` from the dead-end-hull override on the theory that a craft
  step is always legitimate — but the model had "learned" `combine {metal, oil}`
  (hundreds of applies, each "crafted superalloy") and kept re-issuing it over
  the ladder's `buy bearing`. A stranded agent's decision is now forced to the
  ladder's deterministic step for anything except `build` / `finalize`.
- The craft-spin safety net no longer needs `metal >= 5` to act — it buys metal
  when short rather than falling through to the spinning combine.

## [3.2.40] - 2026-09-10

### Fixed
- **The rebuild wedged on the `bearing` craft.** `Ladder::shipCraftStep()`
  crafted the propeller upgrade with `combine {metal, oil}`, but the world's
  physics-tag matcher now resolves that set to **superalloy**, not a bearing —
  so `bearing` stayed at 0, the gear-up loop re-issued the same combine every
  tick (183 `engine`/`superalloy` items minted), and with loop-break suppressed
  for the stranded hull (3.2.39) nothing broke it out. `bearing` is now bought
  from the depot when credits allow.
- **Stranded-rebuild safety net.** If the last ~6 turns of a stranded rebuild
  were `combine`/`buy` with no `build`/`finalize`, an upgrade craft is spinning
  — `AutoPlayer` now forces a bare `build` of the next missing bundle part so it
  completes anyway.

## [3.2.39] - 2026-09-10

### Fixed
- **A stranded hull now actually rebuilds instead of churning.** 3.2.38 made
  `hasDepartCapableShip()` correctly report a gearless hull as a dead end, but
  the recovery — descend, craft/build the ~14-part bundle, `finalize` — is a
  long deterministic sequence that `detectLoop()` reads as "buy/sell churn,
  nothing built", so objective rotation kept yanking the agent onto
  `research`/`wealth` moves that fought the descent. `AutoPlayer` now suppresses
  loop-break while `$shipStranded` and forces `Ladder::suggestion()`'s rebuild
  step over any model pick that is not already `build`/`finalize`/`combine`.

## [3.2.38] - 2026-09-10

### Fixed
- **Venus kept a gearless hull looking flight-capable, so the agent held for a
  window forever and never rebuilt.** `hasDepartCapableShip()` asked whether
  *any* of the four bodies was still reachable. deimos / phobos / mars all land
  in `agent_depart_unreachable` (each `depart`-rejected for a missing
  `landing_gear` part), but venus needs an `acid_skin` the agent can't craft —
  so `departTarget()` skips venus silently and it never enters the unreachable
  set. That left the check permanently true: not stranded → no rebuild → parked
  on `deposit` waiting for a window it can't take. The test is now against
  `GameData::GEAR_BODIES` (deimos / phobos / mars) only; when all three are
  rejected the hull is a dead end and the gear-up path builds a fresh,
  gear-carrying flyer (`finalize` can't add a part to a built vehicle).

## [3.2.37] - 2026-09-09

### Fixed
- **A fresh hull inherited the previous one's "nowhere is reachable" verdict.**
  `agent_depart_unreachable` / `agent_depart_block` persist across restarts, so
  after the agent scrapped a dead-end flyer and built a gear-carrying one, the
  new ship was still refused every destination and never departed.
  `LoopStrategyStateTrait::clearDepartRejections()` now wipes both sets when a
  `finalize` intent APPLYs, so the new hull is judged on its own gear and
  thrust.

## [3.2.36] - 2026-09-09

### Fixed
- **The agent's flyer had no landing gear, so every `depart` to a moon or Mars
  was rejected — and the bot never learned why.** The engine requires a
  `landing_gear` part on the ship for `deimos`/`phobos`/`mars` (Venus is an
  aerostat, exempt), but `GameData::assess()` and `Ladder::flyerReady()` never
  checked for it, so the agent `finalize`d a gearless hull, marked it
  flight-complete, and then spammed `depart deimos` — rejected every time with
  "needs LANDING GEAR", a reason 3.2.34 treated as a transient timing blip.
  - `GameData::assess()['depart'][$body]` now also requires `gear >= 1` for
    `GameData::GEAR_BODIES` (deimos/phobos/mars).
  - `Ladder::flyerReady()` requires a `landing_gear` part in the structural
    floor, so the gear-up ladder builds one before finalizing.
  - A "landing gear" `depart` rejection is now **permanent** for that hull, and
    parks deimos + phobos + mars unreachable in one shot (a gearless ship fails
    identically for all three — no need to burn a window on each).
  - `Ladder::hasDepartCapableShip($raw, $unreachable)` — a flying orbital ship
    that can still reach at least one body. When every destination has been
    rejected the hull is a dead end (`finalize` bundles parts into one vehicle
    and cannot amend it); `AutoPlayer` and the gear-up ladder now treat that as
    "no ship" and build a fresh, gear-carrying flyer instead of holding forever.
  `Ladder::suggestion()` / `stanceMove()` take an optional `$departUnreachable`
  so the ladder sees the dead-end state.

## [3.2.35] - 2026-09-09

### Fixed
- **The held ship now station-keeps in the depart band instead of decaying
  straight through it.** `depart` only fires from altitude 300–600, but a ship
  "holding for a window" loses ~2 alt/tick to orbital decay — left to idle it
  slid out the bottom of the band and spent most of every cycle too low to
  leave, so an opening window kept finding it out of position (observed live:
  agent parked on `deposit {ice}` for 12+ minutes per cycle, never departing).
  The hold now bounces the orbital elevator (no fuel: `ride` down to the base,
  the on-ground rung rides back up to the top) the moment altitude drops below
  the 300 floor, giving a ~600→300 sawtooth that keeps the ship in the band
  ~98% of the time. Transfer fuel (`cryo_fuel`/`hydrogen`/`helium3`) is never
  spent on lift. `Ladder::isHoldingForWindow()` also covers the single
  on-ground tick between the two halves of the bounce so loop-break does not
  rotate objectives on it.

## [3.2.34] - 2026-09-09

### Fixed
- **The agent no longer burns turns on a `depart` the engine will reject.**
  Two failure modes were spamming the intent queue every tick: a `depart` to a
  window that had briefly closed between observe and intent (an observe/intent
  race), and `depart venus` with a flyer whose thrust-to-weight can never make
  the hop.
  - `Ladder::departTarget()` takes an `$unreachable` list and enforces the
    engine's alt 300–600 `depart` band (the upper bound was missing, so a ship
    decaying from 601 still offered a depart).
  - A rejected `depart` arms a 12-tick retry cooldown
    (`LoopStrategyStateTrait::recordDepartRejection()` /
    `departRetryCooldownActive()`); a thrust-to-weight / capability rejection
    ("thrust/(mass", "thrust-to-weight", "ion_thruster (orbital drive)") also
    parks that destination as unreachable for the run — the engine only reports
    the TWR shortfall on rejection, so this is the only way to learn it.
  - `AutoPlayer::step()` sanity-checks every `depart` (model *or* ladder) before
    it goes out: unless `departTarget()` confirms that exact destination is
    serviceable right now and no cooldown is active, it is rewritten to the
    deterministic hold. The forced-hold block no longer lets a stale `depart`
    from `Ladder::suggestion()` slip past.

## [3.2.33] - 2026-09-09

### Fixed
- **Hold covers the whole space tier, not just the orbit band.** A held ship
  loses ~2 altitude/tick to orbital decay; the ~130-tick sink from 300 to the
  ground fell through `isHoldingForWindow` (which required alt 2265 300) into
  loop-break churn. The hold + suppression now run for alt 2265 100, idling
  through the sink; once it lands, the grounded-with-ship path rides it back
  up. `dock` in the hold is gated to the real orbit band (3002013599).

## [3.2.32] - 2026-09-09

### Fixed
- **Hold no longer `dock`-spams.** `dock` needs an asteroid within 2 cells;
  the hold block was returning `dock` for any asteroid in view, and with
  loop-break suppressed while holding it spun on a `dock` that missed every
  turn (nearest was dist 4). It now only `dock`s within range, `mine`s once
  docked, and otherwise idles.

## [3.2.31] - 2026-09-09

### Fixed
- **Unserviceable open window no longer strands the ship.** `isHoldingForWindow()`
  now keys off `departTarget()` — a window that is open but the agent cannot
  service (Venus with no `acid_skin`) counts as holding, so the agent docks and
  mines while it waits instead of thrashing `chop`/`dock`/`mine` in orbit limbo.

## [3.2.30] - 2026-09-09

### Fixed
- **Take the window.** With 3.2.29 the agent stocked fuel and reached orbit,
  then — with **deimos, phobos and mars windows all open** and 91 cryo_fuel on
  hand — sat there selling crystal. An open window is a ~30-tick chance and the
  model frittered it away; the deterministic `depart` only ran on a rejection.
  - `Ladder::departTarget()` — the body to leave for right now (cheapest open,
    shielded, fuelled destination) or `null`. `AutoPlayer` **forces `depart`**
    over any other model pick whenever it returns non-null. `stanceMove` uses
    the same helper.
- **Grounded with a ship = go to orbit.** A finished ship on the ground has one
  job: fuel up, ride the tall elevator, depart. `AutoPlayer` now forces the
  ladder's get-to-orbit move (`buy cryo_fuel` → walk to the elevator → `ride` /
  `launch`) over the model's `mine` / `sell` / `combine` wandering.
- **`combine` can't eat flight fuel.** `AutoPlayer::FLIGHT_CONSUMABLES`
  (`cryo_fuel`, `hydrogen`, `heat_shield`, `acid_skin`) — a model
  `combine {cryo_fuel, silicon}` is refused outright (`helium3` stays allowed:
  it is an `ion_thruster` ingredient).

## [3.2.29] - 2026-09-09

### Fixed
- **The "wait for a launch window" hold.** The agent built a `flies` +
  `orbital_engine` ship and rode to Earth orbit — then thrashed for hours
  because every transfer window was shut and it had no graceful way to wait.
  It cycled `mine` / `move` / `land` / `ride` up there, tripped loop detection
  every ~10 turns, and loop-break's objective rotation then did real damage
  (`combine {slug, stimpack}` — destroying its own weapon ammo + medicine —
  and selling stockpile).
  - `Ladder::isHoldingForWindow()` — a depart-capable ship parked in Earth
    orbit with no open window. `AutoPlayer` now **suppresses loop-break** while
    this holds and **forces the deterministic hold** for any model pick except
    a real `depart`.
  - The hold, in order: stock `cryo_fuel` toward a transfer-sized reserve,
    craft/buy a `heat_shield`, `dock`+mine an adjacent asteroid, else idle.
- **Fuel folded into "flight-ready".** `DEPART_FUEL_MIN` (90 units) — a ship
  that reaches orbit on fumes just parks there. The agent now stocks the
  transfer fuel on the ground before riding up, and while holding in orbit.
  `depart` itself still fires on any fuel + an open window (the engine's Δv
  check is the real gate).
- **Combat kit protected from research.** `AutoPlayer::COMBAT_KIT` — the
  loop-break research pass no longer `combine`s away `slug` / `stimpack` /
  weapons (the ladder's own `speculativeCombine()` already filtered these).
- **No second ship.** A model `build` once a flying ship exists is swapped for
  the fallback instead of accreting a redundant hull.

## [3.2.28] - 2026-09-09

### Added
- **Dynamic material hold window.** The stockpile floor and sell cap are no
  longer fixed at `RESOURCE_TARGET` / `HOARD_CAP` — while the agent is gearing a
  ship they widen for exactly the materials the unbuilt parts still consume, and
  relax again as the parts get built.
  - `GameData::remainingShipBill()` — a live bill of materials: the target bundle
    minus what is already in `loose_parts`, each part's upgrade item expanded to
    raws through the new `CRAFT_INPUTS` map, netted against stock. It shrinks as
    parts are built and empties once the ship is finalized.
  - `Ladder::shipMaterialPlan()` / `floorFor()` / `capFor()` — the per-resource
    floor is raised toward the bill (capped at `PLAN_FLOOR_CEIL` = 50) and the
    sell cap toward `need + 10`. Threaded through the stockpile, sell, walk-to-
    deposit and capitalist-sell rungs, and `speculativeCombine()` now skips a raw
    the pending craft still needs even when it is above the research bar.
  - `AutoPlayer::reserveFor()` — the `BUILD_MATERIAL_RESERVE` floor a research /
    loop-break `combine` may not dip below is raised to whatever the live ship
    bill needs, so a research turn can't eat the metal earmarked for the next
    part (it drops back once those parts exist).

## [3.2.27] - 2026-09-09

### Added
- **`NHA\Brain\GameData`** — a single in-repo transcription of the game's public
  source (`github.com/Recluse/nha-mmo`): the `PART` / `BUILD_COST` /
  `PART_UPGRADES` tables, the `GRAVITY` / `TWR_DEPART` / `DV_NEED` gates, the
  `crafting.py` recipe tree, and `finalizeStats()` — a faithful integer port of
  `finalize_stats` so the brain can *predict* whether a bundle drives / flies /
  can depart **before** it spends the credits. `Ladder::flyerReady()` now calls
  it instead of hand-checking a part count. `GameDataTest` pins the port against
  the source so a future edit that breaks the flyer fails CI, not the live
  server. This is the durable fix for "the brain ran for weeks on guessed
  mechanics" — corrections land in `GameData` now, not scattered prose.

### Changed
- **Ship logic rebuilt on the real physics.** Found the game's public source
  (`github.com/Recluse/nha-mmo`); `engine/vehicles.py` `finalize_stats()` is
  closed-form over summed integer PART constants — there is **no scale / part-count
  gate**. The 3.2.20-26 "mega-bundle" premise was wrong on two counts: the small
  probe bundles finalised inert for lack of a **`cockpit`** (control 0 → cannot
  drive or fly), and the 82-engine bundle failed because mass kills v_air.
  - `flies = control≥1 AND wing_area·v_air² ≥ 10·mass`;
    `v_air = isqrt(90·thrust // drag)`, `drag = max(1, mass//20)`,
    `thrust = Σ jet.thrust + (Σ propeller.thrust_pp)·(Σ engine.power)`.
    Propellers **multiply** engine power → a light frame with a few engines +
    propellers beats an engine stack.
  - `launch` needs `thrust ≥ 4·mass` (`GRAVITY = 4`); `depart` also needs
    `orbital_engine`, which is **`true` iff a part was built `with:{ion_thruster}`**
    — and only the `jet` part accepts it (`+300 thrust, -40 mass`). This is the
    seat the 3.2.11 notes flagged as unsolved.
- `Ladder`: `megaBundleReady()` → `flyerReady()` (cockpit + frame + jet + 3
  engines + 2 propellers + 3 wings + fuel_tank); `driveChainStep()` →
  `shipCraftStep()` (crafts the one upgrade item the flyer still lacks, in order:
  wire → composite → chip → bearing → ion_thruster). New `SHIP_BUNDLE_TARGET`
  (~14-part flyer), `SHIP_PART_UPGRADE`, and source-accurate `PART_UPGRADES` /
  `SHIP_PART_ARCHETYPES`. The expansionist gear-up block builds each part `with`
  its upgrade item, buying `metal`/`crystal` as needed.
- `AutoPlayer`: engine-cap and finalize-stub guards re-pointed at
  `flyerReady` / `shipCraftStep` / the new bundle target.
- `Playbook`: `build`/`finalize` hints and mission steps 1/3/5/6 rewritten for
  the flyer recipe and the `flies`/`launch`/`depart` formulas — the old
  "~34-part MEGA-bundle / engine-heavy / drive chain" text is gone.

### Notes
- Target flyer (checked against the formulas): frame+composite · cockpit+chip ·
  jet+ion_thruster · engine ×3 · propeller ×2 (bearing) · wing ×3 (composite) ·
  tail · fuel_tank ×2 · landing_gear ≈ mass 880, thrust 4900, twr 5.6,
  v_air ~100, wing_area ~54 → flies + orbital_engine, ~900-1500 credits.

## [3.2.26] - 2026-09-09

### Added
- **`jet` is a valid `build` part** (probed: `build{part:jet}` → "insufficient
  for jet (need {metal:10, crystal:2})", not "unknown part"). It's the likely
  lift component for `flies=true` — added to `SHIP_PART_ARCHETYPES` and
  `SHIP_BUNDLE_TARGET` (jet x6), so the live agent tries it on its next
  mega-bundle. `rocket_engine` / `advanced_motor` are NOT build parts (only
  combine outputs); `turbine`/`nacelle`/`prop` all "unknown part".

### Fixed
- Cap runaway `build engine`: told "engine-heavy", the model once stacked **82
  engines** into one un-finalisable bundle (`megaBundleReady` needs a tail +
  cockpit it never built). `AutoPlayer` now swaps a `build engine` for the next
  under-target part once the bundle has target + 2 engines.

### Notes
- `flies=true` still unconfirmed but narrowed: it is NOT scale (a 105-part /
  82-engine bundle finalised `drives=true v=9 flies=false` — more parts = lower
  v). It needs high v_air, which our bundles never produce (v_air stays 0);
  `jet` parts are the untested lead. The agent is credit-drained from the
  probe run (~50 credits) — its 4 deployed auto-miners rebuild the reserve.

## [3.2.25] - 2026-09-09

### Fixed
- `build` was not in `ADVANCING_VERBS`, so a long stretch of `build engine`
  toward the mega-bundle tripped the loop guard's "nothing built" churn check
  every 12 turns and kept yanking the agent off the build to sell/mine.
  Building real parts IS progress — `build` now counts as advancing (the
  same-part-3x guard and dead-part list still catch a rejected-build spin).

### Notes
- The mega-bundle build is working: `loose_parts` reached 15/34 (all 12
  engines + 3 frames) before this fix, with the 2 auto-miners feeding income.
  It is slow — ~5000 credits of materials against ~30 credits/turn — but
  steady and loop-free.

## [3.2.24] - 2026-09-09

### Fixed
- Reserve-protect the drive-chain items (`motor`, `rocket_engine`,
  `advanced_motor`, `engine`, `steel`) in `BUILD_MATERIAL_RESERVE` — a
  loop-break `combine` was throwing a hard-won `advanced_motor` into the Guild
  as junk research.

### Notes
- 3.2.23 stopped the `deploy` loop; post-restart the agent builds the
  mega-bundle correctly (`build engine/frame [+steel]`, 9/34 parts and
  growing) with its 2 auto-miners feeding income. It is slow — a full
  ~34-part bundle is ~5000 credits — but on the right track and no longer
  looping.

## [3.2.23] - 2026-09-09

### Fixed
- The `deploy` churn's root cause: rung 1b kept re-emitting `deploy` because
  the observe feed never flags a deployed vehicle as out. Rung 1b now fires
  ONLY while the agent has 0-1 working (`drives`/`flies`) vehicles total — past
  that it assumes the earlier ones are roaming and leaves any further `deploy`
  to the model (with the `AutoPlayer` repeat-suppressor as backstop). The agent
  already has 2 deployed auto-miners, so `deploy` stops firing.

## [3.2.22] - 2026-09-09

### Fixed
- 3.2.21 tamed but did not stop the `deploy` churn: rung 1b still re-offers
  `deploy` because the observe `vehicles[]` never flags the two auto-mining
  hulls as out. `AutoPlayer` now suppresses `deploy` on the FIRST repeat when
  a `drives`/`flies` vehicle is already in hand (it is mining — `auto_mine`
  x85 in the window) — build / earn instead.

## [3.2.21] - 2026-09-09

### Fixed
- After 3.2.20 the agent (holding the 2 probe-built `drives=true` vehicles)
  wedged spamming `deploy` — the observe feed does not reliably flag a
  deployed vehicle, so rung 1b kept re-offering it. Rung 1b now also treats
  `autonomous`/`roaming`/`out` as deployed, and `AutoPlayer` breaks a run of
  2+ `deploy`s in the recent window (fall back to gearing / earning / idle).

## [3.2.20] - 2026-09-09

### Added
- **`drives=true` recipe cracked by live token-probing** (`ship_probe*.py`).
  It is SCALE, not composition: bundles of 4-19 parts always `finalize` inert
  regardless of mix (even 8 steel-engines, even v_air 46). A **~34-part
  mega-bundle** finalises `drives=true`: `frame x2, engine x12 (with steel),
  wheel x6, wing x6, fuel_tank x3, landing_gear x2, tail x2, cockpit x1`.
  A 58-part bundle with `wings > engines` and no tail/cockpit → inert, so:
  wings <= engines, tail + cockpit required. A `drives=true` vehicle `deploy`s
  as an **auto-miner** (passive resource income).
- `Ladder::megaBundleReady()`, `SHIP_BUNDLE_TARGET`, `SHIP_BUNDLE_MIN`. The
  expansionist gear-up + rung 1 + the `AutoPlayer` finalize guard now build to
  and finalize only the full mega-bundle; the engine build stocks `crystal`
  (each engine costs metal 8 + crystal 1 + steel 1). `SHIP_PART_ARCHETYPES`
  trimmed to the 9 confirmed-valid parts.

### Notes
- Each mega-bundle costs ~5000 credits of materials, so the agent earns /
  deploys miners first. **`flies=true` is still unsolved** — 44 parts / 16
  wings stayed `flies=false` (v_air ~15-46 vs codex's 121); it needs a bigger
  bundle still. `flies` + `orbital_engine` gates `depart` to the inner system.

## [3.2.19] - 2026-09-09

### Fixed
- 3.2.18 built 5 steel-engines and `finalize`d a PURE-engine bundle → `v=0`
  (worse than the mixed bundles that hit v≈28). A ship needs STRUCTURE:
  - The gear-up now builds to a **balanced target composition**
    (`frame` ×1 first as the chassis, `engine` ×5, then `wing`/`wheel`/
    `fuel_tank`/`landing_gear`) instead of engines-then-maybe-structure.
  - `finalize` gate = **3+ engines AND a `frame` AND a `wing` or `wheel` AND
    7+ parts AND fuel loaded** (a pure-engine or fuel-less bundle finalises
    inert).
  - The drive chain buys `carbon` when the steel smelt stalls on it (the live
    run ran `carbon` to 0 and the engine builds started rejecting).
  - The airframe is only started once engines are actually makeable (a
    steel/motor upgrade + `engine` items on hand) — no more bare `frame` on a
    lone metal pile.

## [3.2.18] - 2026-09-09

### Fixed
- Follow-up to 3.2.17's live run: the drive chain now executes (agent crafts
  `motor`, `rocket_engine`, `battery`, `steel`), but every `finalize` was still
  inert — bundles had only 1-2 `engine` parts and the engine build was passing
  `with:{rocket_engine}`, which the `engine` part does not accept (its upgrades
  are `engine`/`motor`/`steel`).
  - `bestDriveUpgrade()` now returns **`steel`** (then `motor`) — codex's
    flagship flyer is literally named "steel-engine".
  - Build up to **5** `engine` parts (was 4); `finalize` gate raised to **8+
    parts with 5+ engines** (codex's flyers are quad/penta-engine, mass ~2000),
    in rung 1, the expansionist gear-up, and the `AutoPlayer` finalize guard.
  - `rocket_engine` / `advanced_motor` added as their own loose parts once the
    engines are down (they are not engine `with:` upgrades); `wheel` added to
    the structural spread.

## [3.2.17] - 2026-09-09

### Changed
- **The bot was guided at the wrong altitude — fixed by reading the agents that
  already have flying ships.** `codex-inventor` holds 4+ vehicles with
  `flies:true, drives:true` named "steel-engine" / "triple/quad/penta-engine";
  `Miner`/`Trader` hold the "Cosmonaut" title and are in space; the **Phobos
  Forward Base colony exists** and is being funded with moon-mined `c_regolith`.
  Our agent had ~0 inventor points and 27 inert hulls because the guidance was
  random-pair `combine` research + a rote 1-of-each `build` rotation — it never
  worked the concrete drive-craft chain.
- New `Ladder::driveChainStep()` + `bestDriveUpgrade()`, now the **top priority**
  in the expansionist gear-up (ahead of the flight kit, the random research, and
  everything else): `iron+magnet+wire → motor`; `engine+motor → rocket_engine`
  (or `engine+composite`); `engine+magnet+motor → advanced_motor`;
  `metal+salt+silicon → battery`; `iron+carbon → steel`. Then the airframe is
  built **engine-heavy** — `build{part:engine, with:{rocket_engine|
  advanced_motor|motor|steel:1}}` ×up to 4, plus frame/wing/fuel_tank/
  landing_gear — and `finalize` fires at 7+ parts with 3+ engines (was a
  5-part any-spread stub). `AutoPlayer`'s finalize guard enforces the same.
- Random `speculativeCombine` research demoted below the drive chain + engine
  build (still ahead of harvest/idle; still never towers).
- Playbook / Expansionist steps rewritten around the drive chain.

## [3.2.16] - 2026-09-09

### Changed
- **No more tower grind, and research is the priority.** Per user direction:
  - The `shipBuildStuck` → build-towers fallback is gone. A shipless
    expansionist never `construct`s a spire — the tower rung and rung 3b
    (buy toward `composite`) are gated off for it, and the Playbook's
    `RESEARCH` / `WEALTH` steps + anti-patterns say "not the goal, skip it".
  - `RESEARCH_SURPLUS` dropped 60 → `RESOURCE_TARGET + 10` (40): a speculative
    `combine` now fires "as resources allow", never digging the reserve.
  - New `Ladder::speculativeCombine()` helper, called from **inside the
    expansionist gear-up** (right after the cheap flight kit, *before* any
    part-building) as well as generic rung 2. The mission is blocked on an
    undocumented mechanic, so inventing the missing item via `combine` — and
    the inventor points it pays — is the way forward.
  - A shipless expansionist gets a dedicated harvest rung that tops two raws
    past the research bar, so `speculativeCombine` keeps finding a fresh pair.
  - Deterministic airframe experiment kept but demoted: build one part of each
    valid archetype only while `metal` is stocked and `loose_parts < 5`, then
    `finalize` the spread. `finalize` gate raised to **5** parts (a 4-part stub
    still came out inert); dropped the `looseHasDrivePart` / inert-hull
    circuit-breaker — the junk hulls are cosmetic (no scrap verb) and stopping
    assembly to grind towers was wrong.
  - `SHIP_PART_ARCHETYPES` trimmed to the 8 confirmed-valid + `wheel`/`axle`.
  - Playbook / Expansionist briefing / PLAYBOOK.md rewritten around
    research-first, no towers.

## [3.2.15] - 2026-09-09

### Changed
- **Stances now serve the one goal (the Solar Accord) and nothing else.**
  `Stance::rank()` has two answers: `aggressive` (a live fight — survival is a
  precondition, so it pre-empts) or `expansionist` (every other turn). It never
  returns `homestead` ("dig in, do NOT fly") or `capitalist` ("credits are the
  game") — those steer *away* from the mission, and the expansionist ladder
  already arms, stockpiles and banks a glut as tactics in service of the flight.
  The two cases stay in the enum only for a stale stored value (corrected on the
  next `pick()` — the mission does not wait out the 40-tick dwell) and the
  ladder's contract tactic; their briefings now point back at the mission.
- Dropped the "weak passer-by within reach → `aggressive`" clause: hunting does
  not further the Accord and only risks a `wanted` tag. Aggressive is now purely
  defensive and hands straight back to `expansionist` once the threat is stale.
- Defaults (`Playbook::systemPrompt()`, `getStance()`) default to `expansionist`.

## [3.2.14] - 2026-09-08

### Fixed
- Live loop: with the LLM host briefly unreachable (ETIMEDOUT / 120s timeout),
  the agent ran ladder-only — and a ship-blocked (`shipBuildStuck`) grounded
  expansionist had **no productive rung**: `$gearingShip` still gated out the
  tower rung, so it bottomed out cycling `deposit ice` / `move (33,114)` /
  `ride`. Now `Ladder::shipBuildStuck()` (3+ inert hulls, no orbital ship)
  **lifts the `$gearingShip` tower gate** — towers are the only thing a
  grounded shipless agent can score, so it builds them (and `combine`s
  `composite` toward them via rung 3b) instead of spinning. The tower rung also
  steps to clear ground first when its cell is already built on.

## [3.2.13] - 2026-09-08

### Fixed
- Two more churn sources for the ship-blocked agent, both on the loop-break /
  model path (the ladder was already clean):
  - The loop-strategy's `wealth` objective still picked the biggest raw
    including `brine` → `sell brine` rejected. Now filtered to
    `Ladder::DEPOT_TRADEABLE`.
  - `construct` on the agent's own cell (an elevator base ringed with its old
    spires) → "a structure already stands on this cell", every time. New
    `Ladder::cellOccupied()` / `stepToClearGround()`: the loop-break `build`
    objective and an `AutoPlayer` guard on a model `construct` now step to
    clear ground first.

## [3.2.12] - 2026-09-08

### Fixed
- With ship-building circuit-broken (3.2.11), the agent dropped to the generic
  ladder and immediately wedged on **`sell brine` × 12** — "depot doesn't trade
  brine". The sell rungs picked the biggest hoard blindly, and `brine` (a mining
  byproduct the Earth depot won't buy, 99 held) was it. Both sell rungs now pick
  the biggest **depot-tradeable** raw (`Ladder::DEPOT_TRADEABLE`, probed from
  `GET /depot`), skipping `brine` and off-world body resources.

## [3.2.11] - 2026-09-08

### Fixed
- The 3.2.10 run still piled up hulls (15 now) — the drive-part heuristic was
  wrong (a `propeller` bundle *still* finalizes `drives=false`), and the
  loop-strategy's forced `finalize` bypassed the guard. **Circuit-breaker:**
  once the agent has **3+ inert hulls** and no orbital ship, the expansionist
  `stanceMove` skips the whole gear-up/build/finalize block (`$shipBuildStuck`)
  and `AutoPlayer` refuses any `finalize` (model- or loop-forced) — the agent
  drops to the generic ladder (towers / co-op invest / stockpile) so it scores
  while the assembly recipe stays unsolved. Rungs re-arm automatically if a
  real ship ever appears.

### Changed
- Vocabulary: `engine` is a **valid** part (upgrades `engine`/`motor`/`steel`);
  `motor`, `turbine` are **not** parts (they're combine outputs / upgrade
  items). `cockpit` upgrades: `chip`/`glass`/`lens`/`casing`. **No known part
  accepts `ion_thruster` as a `with:` item** — `frame`, `propeller`, `engine`,
  `cockpit` all enumerate their upgrades and none include it; where the orbital
  drive seats is unsolved. Removed the ion_thruster-on-engine guess.

## [3.2.10] - 2026-09-08

### Fixed
- The 3.2.9 run piled up **10 inert vehicles** — the model kept `finalize`-ing
  driveless part bundles, and the 3.2.7-style "stop if inert hulls exist" gate
  can't be used (no scrap verb → permanent brick). `finalize` is now gated on
  the **bundle itself**: 4+ loose parts *and* one of the drive archetypes
  (`propeller`/`engine`/`motor`/…) present (`Ladder::looseHasDrivePart()`),
  enforced against the model's pick in `AutoPlayer`, not just the ladder's.
- Ship parts **cost metal** (`landing_gear` 3, `cockpit` 4+crystal, `frame`
  5+composite, `fuel_tank` 3, `tail` 2) — the agent was starving the build
  rotation. Gear-up now stocks `metal` to 15 before building parts.

### Changed
- More `build` vocabulary from the live run: **valid** adds `fuel_tank`,
  `propeller` (upgrades `bearing`/`alloy`), `tail`; **rejected** adds `wheels`,
  `body`. `SHIP_PART_ARCHETYPES` / `DEAD_BUILD_PARTS` / `PART_UPGRADES` / the
  Playbook hint updated. Where the `ion_thruster` orbital drive actually seats
  is still unknown (`frame` and `propeller` both refuse it).

## [3.2.9] - 2026-09-08

### Changed
- More of the `build` vocabulary mapped from the 3.2.8 live run:
  **valid** — `landing_gear`, `cockpit`, `wing`, `frame`; **rejected** —
  `chassis`, `hull`, `rotor`, `airframe`, `thruster`. And `frame`'s `with:`
  upgrades are `steel`/`alloy`/`composite`/`superalloy`, **not** `ion_thruster`
  (`Ladder::PART_UPGRADES`). `SHIP_PART_ARCHETYPES` now leads with the confirmed
  four and appends drive-part candidates (`wheel`, `engine`, `motor`,
  `propeller`, …) — an inert `finalize` means the parts have no drive.
  `DEAD_BUILD_PARTS` and the Playbook `build` hint updated to match.
- `finalize` now needs **4+** loose parts (was 3 — three still produced an
  inert hull), and stops once **2 inert hulls** have piled up: the recipe is
  still missing its drive part, so parts are held for the search rather than
  spent on more junk. `Ladder::inertVehicleCount()`.

## [3.2.8] - 2026-09-08

### Fixed
- 3.2.7's "don't `finalize` while an inert hull sits in `vehicles`" gate
  **deadlocked** the assembly: the junk `ship_v1` from the earlier one-part
  finalize is already in the live agent's `vehicles`, and there is no `scrap`
  verb, so `finalize` would have been suppressed forever. Dropped the
  `hasDeadHull()` guard from the `finalize` rungs — the **3+ loose-parts**
  threshold alone stops the one-part junk, and `finalize` on a fuller set can
  now supersede the dead hull. `hasDeadHull()` is kept only for the `deploy`
  skip / override.

## [3.2.7] - 2026-09-08

### Fixed
- The 3.2.6 live run found `build{part:landing_gear}` is a **confirmed hit**
  (`chassis`/`thruster`/`ion_thruster` are not) — but then `finalize`d a ship
  from that one part into an **inert hull** (`drives=false, flies=false,
  fuel_cap=0`), and the agent spent the next dozen turns trying to `deploy` it
  ("no vehicle that drives or flies") until the loop-breaker forced a
  `combine algae+ion_thruster` that **spent a real ion_thruster** into the Guild.
  - `finalize` now waits for **3+ loose parts** (rung 1 and the expansionist
    gear-up), and is suppressed while an inert hull already sits in `vehicles`
    (`Ladder::hasDeadHull()`).
  - `Ladder::hasAnyVehicle()` now means a vehicle that actually `drives` or
    `flies`; rung 1b `deploy` skips an inert hull.
  - Flight-kit items (`ion_thruster`, `heat_shield`, `acid_skin`, `cryo_fuel`,
    `helium3`, `hydrogen`, `landing_gear`, `fuel_tank`) join
    `BUILD_MATERIAL_RESERVE` at 1, so a research / loop-break `combine` can
    never consume the last one.
  - `AutoPlayer` rewrites a model `build` that names a known-dead part
    (`DEAD_BUILD_PARTS`) to the ladder's rotated archetype, and the
    same-part-three-times guard rotates to the next archetype instead of
    stalling (rotating *through* parts is the search, not a loop).
  - Part rotation widened to `Ladder::SHIP_PART_ARCHETYPES`
    (`fuel_tank, landing_gear, wing, wheel, hull, frame, cockpit, rotor,
    airframe, body`), fitting `with:{ion_thruster:1}` on a structural part.

## [3.2.6] - 2026-09-08

### Fixed
- **`wait` is not an NHA verb** — the engine rejects it as *"unknown verb"*, so
  every override and fallback that returned `wait` was a wasted, rejected tick.
  New `Ladder::noop()` returns the designed no-op (`deposit` of one unit already
  held, balance unchanged); `AutoPlayer::idle()` wraps it, and a submit-time
  guard rewrites any lingering `wait` decision. `deposit` replaces `wait` in the
  Playbook verb catalogue and ladder text.

### Changed
- The `build` `part` argument is an **undocumented enum** — 3.2.5's fixed guess
  (`part: thruster`) drew *"unknown part thruster"* every tick, and the live
  model guessed the same. The gear-up rung now **rotates** through the plausible
  archetypes (`fuel_tank`, `landing_gear`, `chassis`, `frame`, `hull`, `wing`,
  `wheel`, `cockpit`) by tick so the deterministic path actually searches the
  vocabulary; a hit lands in `loose_parts` and the rung above it `finalize`s.
  The Playbook's `build` hint and GEAR-FOR-DEPARTURE step carry the same list
  and an explicit "don't repeat a rejected part" rule.

### Notes
- Live run confirmed 3.2.5 killed the `land`-with-no-vehicle spam and the agent
  now crafts a `heat_shield` on the way up — real mission progress. The one
  remaining blocker is discovering a valid `build` part name, which the running
  agent (ladder + live LLM) is now probing.

## [3.2.5] - 2026-09-08

### Fixed
- The real stall behind 3.2.1–3.2.4: the brain treated a loose **`ion_thruster`
  resource** in the inventory as a flyable ship. It is not — a ship exists only
  once `finalize` has produced a `vehicles[]` entry. So the agent believed it
  was "flight-ready", rode a 120 m elevator spire into space, decayed straight
  back, and then spammed `land` (rejected every tick: *"no controllable vehicle
  to land with"*). Live `/observe` on the running agent confirmed `vehicles: []`
  with `ion_thruster: 2` in hold.
  - `$flightReady` now requires `Ladder::hasOrbitalShip()` (a finalized orbital
    vehicle) — never a loose `ion_thruster` resource. Same correction in
    `AutoPlayer`'s vanity-tower / ride / launch / depart override.
  - New `Ladder::hasAnyVehicle()` + `descentWithoutShip()` — a `land` /
    `land_body` / `land_moon` picked with no vehicle is swapped for the real way
    down (ride the elevator, else wait out orbital decay), in the ladder's
    rung 2b and as a hard `AutoPlayer` filter.
  - New `Ladder::orbitElevator()` — ride only an elevator that actually reaches
    orbit (height ≥ 300); a shipless bounce on a 120 m spire scores nothing.
    With a real ship and no tall elevator, `launch` toward 300+ instead.
  - Gear-up rung, kit complete but still no vehicle → issue `build`
    (`part: thruster`, `with: {ion_thruster: 1}`) then `finalize`, rather than
    riding up prematurely. `AutoPlayer` breaks a 3-in-a-row `build` with no
    resulting vehicle so a wrong `part` arg cannot spin quietly.
  - The vanity-spire gate (`$gearingShip`) now holds until a real ship is in
    hand, not just until the kit is bought.

## [3.2.4] - 2026-09-08

### Fixed
- 3.2.3 got the agent to `finalize` its first ship — then stalled: `finalize`
  consumes the loose `ion_thruster` into the vehicle, so `$has('ion_thruster')`
  went back to 0 and every "flight-ready?" check said no. Now a `finalize`d
  vehicle with an `orbital_engine` counts as flight-ready
  (`Ladder::hasOrbitalShip()`), in the ladder and in `AutoPlayer`'s overrides.
- An expansionist ship in orbit no longer `land`s back to Earth when no
  transfer window is open — rung 2b is suppressed for it, and `AutoPlayer`
  swaps a model `land` for `depart` (window open) / `dock` (asteroid) /
  `wait` (hold for the window).
- Rung 1b (`deploy` an idle vehicle for passive mining) skips an expansionist's
  orbital ship — that one is for flying, not deploying.

## [3.2.3] - 2026-09-08

### Fixed
- The 3.2.1/3.2.2 gear-up rung tried to `buy motor` — but `motor` is crafted,
  not stocked, so the depot rejected it and the agent bought motor forever.
  Rewritten against what the depot actually sells (probed live): `ion_thruster`,
  `cryo_fuel` and `superalloy` are all buyable, so a credit-flush grounded
  expansionist now just **buys the ion_thruster and cryo_fuel**, with `combine`
  (motor from magnet+copper+energy_cell, cryo_fuel from ice+coal/oil) as the
  low-credit fallback. `heat_shield` is only chased for the Mars/Venus legs —
  a fuelled ion-thruster ship rides to orbit for a moon hop without one.

## [3.2.2] - 2026-09-08

### Fixed
- Follow-up to 3.2.1 (live behaviour was still ~50% spires): `AutoPlayer` now
  also overrides a **model `construct box/cylinder/sphere/cone/pyramid`** pick
  (not just `ride`/`launch`/`depart`) when the agent is expansionist, grounded
  and shipless — swapping the vanity spire for the gear-up move.
  Colony/terraform/extractor/monument `construct`s pass through.
- `Brain\Ladder` gear-up rung extended: it now also `combine`s **superalloy**
  (metal + wood) toward a `heat_shield`, and its "buy a missing input" fallback
  covers `helium3` and `metal` (a `buy` the depot does not stock is just
  rejected and the ladder moves on).

## [3.2.1] - 2026-09-08

### Fixed
- The 3.2.0 mission re-point flipped the stance and prompt but the agent still
  could not execute the flight chain — it spammed short `construct` spires on
  the ground and once rode the elevator up with no ship and spent a dozen turns
  landing back down. Three changes make the ladder actually drive toward orbit:
  - `Brain\Ladder` expansionist `stanceMove` gains a **GEAR UP** rung: on the
    ground without a fuelled ion-thruster ship it deterministically
    `combine`s the fixed-recipe flight-prep items (`ion_thruster` =
    fusion fuel + motor + semiconductor, `hydrogen` = water + motor,
    `heat_shield` = superalloy + composite), `finalize`s once it holds parts,
    or buys a missing input — never `ride`/`launch` before the ship is ready.
  - The `construct`-tower rung is **skipped** while an expansionist agent is
    gearing a ship on the ground, even when it holds the composite + metal for
    one. A ship already in hand re-opens towers as trip funding.
  - `AutoPlayer` overrides a model `ride`/`launch`/`depart` pick when the
    agent is expansionist, on the ground, and not flight-ready — substituting
    the gear-up suggestion.
- `AutoPlayer::detectLoop()` gains a two-verb-domination check: a window whose
  top two args-stripped verbs own ~85%+ of it with no real forward step
  (`finalize`/`build`/`depart`/`deploy`/`invest`/`land_*`/`dock`) is flagged
  ("spinning on construct/move …"). Catches spire-spam, which slipped past the
  churn check because `construct` counts as advancing.

## [3.2.0] - 2026-09-08

### Changed
- The autoplay brain is re-pointed at the **Solar Accord** era meta-win
  (Mars terraformed, Venus held, a Moon base) instead of grinding the
  leaderboards. Points, towers and trades are now framed as *means to fund the
  mission*, not the goal.
  - `Brain\Playbook` system prompt: a `THE MISSION` block up top; `HOW YOU WIN`
    rewritten as `HOW YOU MOVE THE MISSION` (reach a body & build there → invest
    in co-op boards → fund it on Earth); the decision ladder re-ordered so
    "build on a body" / "go" / "gear for departure" sit above towers, inventing
    and wealth; the elevator anti-pattern relaxed so reaching orbit to `depart`
    is encouraged. `VERBS` gains `depart`, `land_moon`, `land_body`, `distress`,
    `assist` (the LLM could not pick them before) and the expansion `construct`
    shapes.
  - `Brain\Stance::rank()`: **expansionist** is now the default drive the moment
    the agent is minimally geared (weapon + ammo + a medicine) on Earth, not
    only once it is already in space. Homestead is just the pre-armed early
    phase; capitalist keeps its market-work gate.
  - `Brain\Ladder` expansionist `stanceMove`: a real flight chain — `land_*` on
    arrival, `construct` colony/extractor/terraform on the surface, `depart`
    from Earth orbit when fuelled/shielded and a window is open (moon first),
    `dock` for space metals, or walk to the elevator and `ride`.
  - Loop-break objective rotation leads with `expand` (run the expansionist
    ladder) instead of `explore`.

## [3.1.37] - 2026-09-08

### Fixed
- `Brain\Stance::rank()` could latch the **capitalist** stance permanently. It
  ranked capitalist on "fat credit pile + not currently holding
  `composite>=2 && metal>=8`", but the capitalist steer sells surplus and never
  lets the agent assemble those materials — so the exit condition could never
  become true and the agent day-traded forever (observed live: endless
  `buy carbon -> combine aluminium+carbon -> sell` with no `construct`).
  Capitalist now also requires real market work — a contract whose `want` the
  agent covers, or a raw stockpiled past the hoard cap. With neither, the agent
  drops to homestead and spends its credits on a build.
- `AutoPlayer` no longer permanently blacklists a `combine` set that was
  rejected merely for lack of ingredients that turn (`recordDeadCombine` now
  skips production recipes and stock-shortage rejections), and a production
  recipe (`aluminium+carbon → composite`) is fully exempt from the dead list —
  a known-good, re-craftable recipe can't legitimately be "dead". One short
  `aluminum+carbon` had wedged the composite build for good.
- `AutoPlayer::detectLoop()` no longer lets a single repeated `combine` set
  (a production recipe, or one fixation) count as "advancing" — a window whose
  only non-trade action is `combine aluminium+carbon` on repeat is now flagged
  as churn. Two or more distinct combine sets still count as genuine research.

## [3.1.36] - 2026-09-08

### Fixed
- `AutoPlayer::detectLoop()` now catches the churn wedge it was missing: an
  agent that alternates two "productive" verbs forever (`mine ↔ sell`,
  `buy ↔ sell`) with nothing that advances the score. Because both verbs count
  as work and neither exact `verb:args` fingerprint dominated, the dominant-
  action, tail-cycle and traversal-window checks all passed it through, so the
  loop guard never armed and the agent traded in circles indefinitely. A new
  check flags a 10+ turn window containing **zero** advancing actions
  (`construct` / `finalize` / `combine` / `deploy` / `invest` / `fulfill` / …),
  reporting `buy/sell churn`, `churn: <verb>/<verb> on repeat`, or
  `no advancing action for N turns` — which arms the existing forced-objective
  rotation (explore → wealth → build → research).

## [3.1.35] - 2026-09-08

### Changed
- `composer.json` no longer carries a `repositories` block. The published
  package installs from Packagist alone (`team-reflex/discord-php: dev-master`,
  `discord-php/http: dev-master as 10.1.7`); a fresh `git clone` + `composer
  install` no longer depends on sibling checkouts sitting at `../DiscordPHP`.
  For local development against checked-out `DiscordPHP` / `DiscordPHP-Http`,
  put the `path` repositories in your **global** Composer config instead —
  `AGENTS.md` → "Local development against sibling checkouts".

## [3.1.34] - 2026-09-08

### Changed
- `Brain\AgentBrain` split again: the deterministic fallback — `suggestion()`
  (the full ladder), `defensiveAction()` (rung 0 combat), the private
  `combatMove` / `armMove` / `stanceMove` / `bestMedicine` helpers, and the
  `CREDIT_FLOOR` / `RESOURCE_TARGET` / `HOARD_CAP` / `RESEARCH_SURPLUS`
  constants — moved to a new `Brain\Ladder`. `AgentBrain` is now only the LLM
  round-trip (`decide()` / `parseDecision()` / `summarize()`), 600 → 122 lines.
  `AutoPlayer`, `PromptBuilder` and `Stance` now call `Ladder::…` directly
  instead of reaching into `AgentBrain` statics; ladder tests moved to
  `LadderTest`. No behaviour change. `docs/PLAYBOOK.md` updated.

## [3.1.33] - 2026-09-08

### Changed
- `Repository\AbstractRepository` gains a protected `fetchOut($class, $endpoint)`
  helper — `GET` the endpoint and hydrate the body into one `Out` part — and the
  23 concrete `getX()` methods across `World`/`History`/`Social`/`Economy`/
  `Meta`/`Agent` repositories that hand-rolled that exact
  `->then(fn($data) => $this->factory->part(...))` now call it. The "fetch →
  hydrate" contract lives in one place; `getArena()` (raw body),
  `DepositsRepository` (list) and `IntentRepository::getIntentStatus()` (404/410
  → synthetic `gone`) keep their bespoke bodies. `AbstractRepositoryTrait` is a
  vendored port of DiscordPHP's trait and is deliberately left untouched.

## [3.1.32] - 2026-09-08

### Changed
- `StateStore` split: the ~30 accessors moved into six cohesive traits under
  `NHA\State\` — `IdentityStateTrait` (default agent / Discord users / autoplay
  flag / command signatures), `PositionStateTrait`, `AutoplayLeaseTrait`,
  `DecisionLogTrait`, `CombineMemoryTrait` and `LoopStrategyStateTrait`
  (forced-objective rotation + cooldown + stance + inventor-points trend).
  `StateStore` itself is now just the JSON file: load, the shared `$data`, and
  the atomic `save()` the traits call — 723 → 87 lines. No API change (every
  method, constant and static stays on `StateStore` via the traits); some
  magic caps became named constants (`DECISION_LOG_CAP`, `TRIED_COMBINES_CAP`,
  `DEAD_COMBINES_CAP`).

## [3.1.31] - 2026-09-08

### Changed
- `Commands` split: the how-to-play guide — the ~110-line `HELP` content block
  plus `help()` / `resolveHelpKey()` / the private `helpContainer()` select-menu
  wiring — moved to a new `NHA\HelpGuide` (`HelpGuide::SECTIONS`,
  `HelpGuide::render()`, `HelpGuide::resolveKey()`). `Commands` drops from 1052
  to 891 lines. `Commands::HELP` aliases `HelpGuide::SECTIONS` and
  `Commands::help()` / `Commands::resolveHelpKey()` are thin delegators, so
  `SlashCommands`, `ChatCommands` and every caller are unchanged.

## [3.1.30] - 2026-09-08

### Changed
- `Brain\AgentBrain` split: the ~300-line situation-digest builder
  (`summarize()` plus its `pairs` / `rows` / `compactArgs` formatters) moved
  to a new `Brain\PromptBuilder` (`PromptBuilder::build()`). `AgentBrain` is
  now decide / parse / the deterministic `suggestion()` ladder, 948 → 600
  lines. `AgentBrain::summarize()` stays as a thin delegator, so callers and
  tests are unchanged; `PromptBuilder` calls back to `AgentBrain::suggestion()`
  for the "SUGGESTED next action" line. `docs/PLAYBOOK.md` component diagram
  and anchor table updated.

## [3.1.29] - 2026-09-08

### Changed
- `Brain\Playbook` — rung 4b rewritten as **GET A VEHICLE**: the agent has
  never `finalize`d one, so once it is stocked and grounded with no vehicle in
  hand it should read the part costs from the recipe block, `build` the
  cheapest affordable part, repeat a part per turn until it can `finalize`,
  then `deploy` — ahead of inventing and a second tower. A rejected `build`
  means "can't afford that part", not "retry".

## [3.1.28] - 2026-09-08

### Changed
- Autoplay: the brain no longer rides the elevator (`launch` / `ride` up /
  `depart`) whenever it feels like it. After any location change, a
  `TRANSIT_DWELL_TICKS` (8-turn) window makes `AutoPlayer::step()` substitute a
  local ladder action for a "leave" pick — unless the ladder is genuinely
  exhausted here (its fallback is the same transit verb) or a loop-break
  objective wants to explore. `land` (coming home) is never gated. This
  replaces the narrower "ride straight after riding" anti-bounce check.
- `Brain\Playbook` system prompt gains an **ELEVATOR ABUSE** anti-pattern:
  exhaust `mine`/`chop`/`gather`/`combine`/`construct`/`sell` where you stand
  before changing location; never ride up, find nothing, and ride back.
- `docs/PLAYBOOK.md` — the one-turn flow diagram now shows the transit-dwell
  guardrail in place of the old ride check.

## [3.1.27] - 2026-09-08

### Changed
- `bot.php` went from 848 lines to ~175 by extracting cohesive pieces into
  `NHA\Bot\*` classes (no behaviour change):
  - `Bot\Env` — `.env` location + loading + typed getters.
  - `Bot\Replies` — `Commands::` promise → chat / slash response, `❌` on error,
    `flattenOptions()`.
  - `Bot\ChatCommands` — the whole `!nha` prefix-command tree.
  - `Bot\SlashCommands` — the lazy slash registration (option builders, the
    `/nha` group + dispatch, per-user single-verb commands, signature-diffed
    `createCommand`).
  - `Bot\ChannelRelay` — the poll → dashboard + `MESSAGE_CREATE` → `say` bridge.
  - `Bot\AutoplayLoop` — the periodic `AutoPlayer::step()` loop with its
    busy-skip and warn-throttle.
  `bot.php` now just wires configuration → `NHA` → these components → `run()`.

## [3.1.26] - 2026-09-08

### Fixed
- Bump the `Http::VERSION` / `NHA @version` markers that were missed in 3.1.25.

## [3.1.25] - 2026-09-08

### Added
- `NHA_BRAIN_CHANNEL_ID` — the autoplay play-by-play ("thinking dialogue") is
  posted there when set, keeping the main `NHA_CHANNEL_ID` for controls,
  inventory and the world dashboard. Falls back to `NHA_CHANNEL_ID` when unset.

## [3.1.24] - 2026-09-08

### Fixed
- The Discord channel relay re-posted the full observation dashboard on every
  poll. `AgentObservation::getThreats()` (the `alerts` list) lingers for many
  ticks after a single hit, and the relay treated "any threat present" as "post
  now" — so one old `attacked` alert spammed the dashboard every few seconds.
  It now tracks the newest forwarded alert tick and relays only a genuinely new
  threat (or a new world-chat message).

## [3.1.23] - 2026-09-08

### Added
- Strategic **stances** — the agent is no longer one rigid ladder. Each turn
  `Stance::pick()` chooses `homestead` (default) / `aggressive` / `capitalist` /
  `expansionist` from the observation, with hysteresis (40-tick dwell; combat
  pre-empts it) and persistence in `state.json`. The stance re-flavours the
  system prompt (a `Stance::briefing()` block spliced at the top, framed as a
  steer not a script) and adds a light deterministic nudge (ladder rung 1d):
  aggressive tops ammo and closes on a weak target; capitalist fulfils a
  covered contract or banks a raw surplus; expansionist builds an extractor,
  docks an asteroid, or heads for the elevator. Survive / defend / arm and the
  anti-patterns stay stance-independent. The status line shows `🤖 [stance]`.

### Changed
- Research is now a luxury, not a grind. Rung 2 (`combine` for inventor points)
  fires only on a genuine surplus — at least two raws each `RESEARCH_SURPLUS`
  (60) deep, on top of the normal stockpile — regardless of stance or whether
  points are "paying". Below that, the agent builds or banks instead.

## [3.1.22] - 2026-09-08

### Added
- Combat self-defence and self-arming.
  - `AutoPlayer::step()` checks `AgentBrain::defensiveAction()` right after
    `observe` and, if the agent is in a fight (a recent `attacked` alert, a
    `last_robbed_by`, or a hostile closing in while hurt), acts on it
    immediately — heal below ~35% HP, `attack` back if armed and the aggressor
    is in range, otherwise `move` directly away to break contact — skipping the
    brain and the loop guard entirely. Logs a `🛡️` line.
  - The `suggestion()` ladder gains an ARM rung (after finalize/deploy, before
    research): out of combat with no medicine / weapon / ammo → `buy` a
    `stimpack`, then a `kinetic_gun`, then `slug` ×5.
  - Ammo and combat kit are excluded from the sellable-raws set. Playbook rung
    1 is now SURVIVE / DEFEND with an explicit combat sub-ladder, plus rung 1b
    ARM YOURSELF.

## [3.1.21] - 2026-09-07

### Fixed
Turn-flow review (`AutoPlayer::step()`):
- The forced-objective cursor is no longer advanced (nor the cooldown armed)
  before `brain->decide()`. The turn now *peeks* the next objective for the
  prompt and only commits the rotation (`bumpForcedObjective`) once the brain
  call has returned — a transient brain failure no longer burns a rotation.
  New `StateStore::peekNextForcedObjective()`.
- The `research` loop-break no longer picks a tower material that is only at
  its reserve — the combine guardrail would just block it, defeating the break.
- A `wait` turn and a `🔁` skipped turn now record a `wait` decision, so a
  wait/skip streak is visible to `detectLoop()` (previously invisible — the
  log did not grow, so the loop guard never fired).

## [3.1.20] - 2026-09-07

### Fixed
- The 3.1.19 `land`/`launch` filter created a blind spot: an agent wedged on a
  structure `land` cannot get past would `land`-spam forever undetected.
  `detectLoop()` now records altitude on every decision and flags a `land` /
  `launch` run where the altitude has not moved (`stuck land at altitude N`) —
  which also bypasses the loop-break cooldown. `loopBreakDecision` steps the
  agent off its cell when it is aloft at altitude ≤ 5.
- A `combine` refused by the guardrail (dips a reserve, world-known, …) is now
  recorded as tried even though it never went out, so the brain stops
  re-picking a set it cannot submit (the "combine composite + ice" fixation).

## [3.1.19] - 2026-09-07

### Fixed
- The loop guard no longer flags a descent. `land` / `launch` are bounded,
  self-terminating altitude changes — `detectLoop()` now ignores them, so a
  multi-turn descent from the elevator is not mistaken for "repeating land"
  (and the `build` loop-break, which is itself `land` while aloft, no longer
  fights it). PLAYBOOK loop-guard diagram updated.

## [3.1.18] - 2026-09-07

### Changed
- Selling and harvesting are now conditional on need.
  - `sell` fires only when credits are below `AgentBrain::CREDIT_FLOOR` (300)
    or a single raw has piled past `HOARD_CAP` (80); it never dips below the
    `RESOURCE_TARGET` (30) stockpile except in a genuine credit emergency
    (then it keeps a token 10). The two old unconditional `sell` rungs are gone.
  - Harvesting and repositioning fill each raw up to `RESOURCE_TARGET` (30)
    instead of stopping at "not short (< 15)".
  - Grabbing a resource you are standing on runs *before* spending credits on
    buy-to-build; buy-to-build itself now gates on `CREDIT_FLOOR`.
  - The loop-break `wealth` objective keeps a working 10 rather than dumping
    the whole stack.
  - `docs/PLAYBOOK.md` ladder diagram updated to match.

## [3.1.17] - 2026-09-07

### Changed
- Tower materials are no longer banned from research combines — they are
  reserved. A research `combine` may spend `metal` / `aluminum` / `carbon` /
  `composite` / `alloy` / `steel` / `titanium` / `superalloy`, but only the
  surplus above a per-material reserve (`metal` 8, `composite` 2, the rest 4);
  if the spend would drop the agent below the reserve the combine is refused
  and it goes back to buying / building. `aluminium + carbon → composite`
  stays exempt.

## [3.1.16] - 2026-09-07

### Fixed
- The buy-to-build pipeline was leaking. An audit showed the brain buying
  `metal` / `aluminum` as suggested and then immediately combining them into
  junk research sets, so `composite` never accumulated. The guardrail now
  refuses any research `combine` whose ingredients include a tower material
  (`metal`, `aluminum`, `carbon`, `composite`, `alloy`, `steel`, `titanium`,
  `superalloy`) and falls through to the buy-to-build / construct ladder. The
  one allowed use of those in a `combine` is `aluminium + carbon → composite`.

## [3.1.15] - 2026-09-07

### Added
- The strategy now spends credits instead of only earning them. When a ground
  agent has a credit pile but no `composite`/`metal` and no fresh research, the
  ladder buys its way to a tower: `buy metal` → `buy aluminum` + `buy carbon` →
  `combine` them into `composite` → `construct` — turning an idle credit stack
  into builder points, the one reliable scorer it could not otherwise reach.
- Passive-income rungs: `deploy` a finished-but-idle vehicle to roam and mine
  autonomously; `invest` credits into any still-open Station module / colony
  board (a no-op while everything is complete). Playbook rung 4b spells out the
  `build → finalize → deploy` and `construct extractor` income loops.

## [3.1.14] - 2026-09-07

### Fixed
- The loop guard thrashed once it engaged. A live audit showed it firing every
  single turn — the loop-break `move`s it issued kept the "no productive
  action" window full, so it re-triggered on its own output and rotated through
  all four objectives in four turns.
  - A 24-tick cooldown (`StateStore::loopBreakCooldownActive()`) after each
    break: no re-break until the forced objective and the brain turns after it
    have had a chance to change the situation.
  - A stuck `wealth` / `build` / `research` objective now harvests a deposit it
    is standing on before falling through to a `move`, so the break itself is a
    productive turn instead of more repositioning.

## [3.1.13] - 2026-09-07

### Fixed
- The loop detector missed noisy loops. A live audit caught the agent
  oscillating `land ↔ move (elevator base) ↔ ride` for ~15 turns without
  `detectLoop()` firing — the doubled steps and the odd `chop` kept any single
  action under the dominance bar and broke the exact-cycle match. Added two
  checks: the same `move` target chosen 3+ times in the window, and a window
  that is 6-of-8 traversal verbs (`move`/`ride`/`land`/…) with nothing
  productive. Lowered the exact-dominance bar 60% → 55%.
- A forced `explore` now steps ~28 cells (was 13) so it actually clears
  whatever the agent was circling.

## [3.1.12] - 2026-09-07

### Added
- Loop guard. `AutoPlayer::detectLoop()` scans the last ~12 decisions for one
  action dominating the window or a short 2-4 move pattern repeated three times.
  When it fires, `StateStore::bumpForcedObjective()` rotates the agent to a
  different kind of goal (`explore → wealth → build → research`) and
  `AutoPlayer` acts on it deterministically for that turn — sell the biggest
  stack, construct/land, try a genuinely fresh pair, or walk a long step in a
  rotating direction — whatever the brain returned. The forced objective is also
  handed to the brain as a `LOOP DETECTED` directive and expires after 45 ticks.
- The rolling decision log kept in `state.json` grows 15 → 24 entries so cyclic
  patterns are visible across enough repetitions to catch.

## [3.1.11] - 2026-09-07

### Added
- A durable dead-combine list. When a `combine` intent comes back `rejected`
  (the Inventors' Guild ruled the tag-set makes nothing), its signature is
  recorded in `state.json` via `StateStore::recordDeadCombine()` and never
  submitted again by that agent — it overrides even the production-recipe
  exemption, survives restarts, and is fed to the brain as an explicit
  "NEVER pick these" line.
- The per-run "already submitted" list (`agent_combine_sigs`) cap is raised
  400 → 2000 so a long-running agent does not FIFO-evict an old set and retry it.

## [3.1.10] - 2026-09-07

### Fixed
- Autoplay no longer bounces between the ground and orbit. When there is
  nothing to do off the ground — no asteroid to dock and mine, no parts to
  finish — the ladder now returns `land` instead of stalling, and it no longer
  suggests riding an elevator up with no station or asteroid work lined up.
- A `ride` chosen straight after another `ride` is treated as a loop and
  swapped for the ladder's move.
- The fallback sells a modest surplus (12+ of a raw) rather than skipping the
  turn when research has stalled and there is no build move — far fewer wasted
  `🔁` turns.

## [3.1.9] - 2026-09-07

### Fixed
- The "is research paying" check now uses the recent trend, not the absolute.
  Inventor points never drop, so `inventor_points > 0` stayed true forever once
  an agent had invented anything — the fallback would never actually reach
  infrastructure. `StateStore::noteInventorPoints()` records the score each turn
  and reports paying only when it rose this turn or within the last 15 minutes;
  once discoveries dry up the fallback drops to build / wealth / harvest.

## [3.1.8] - 2026-09-07

### Changed
- The infrastructure fallback now keys off whether research is actually paying.
  When `inventor_points` are above 0 a refused set is swapped for a *fresh*
  untried pair (research still works — keep at it); only once points have
  stalled at 0 does the fallback skip speculation and go to build / wealth /
  harvest. Fixes an in-space agent idling when it held no ground-build
  materials despite research still scoring.

## [3.1.7] - 2026-09-07

### Changed
- When a research `combine` is refused (world-known or already tried this run),
  autoplay now works on infrastructure instead of just selling a surplus. The
  fallback runs the shared decision ladder with speculation switched off:
  `finalize` loose parts → `construct` a tower when `composite` + `metal` are in
  hand → sell a glut → harvest a shortage → move toward the materials a build
  needs. `AgentBrain::suggestion()` is now `public static` with an
  `$allowSpeculation` flag so both callers share one ladder.
- Production recipes (`aluminium+carbon` → `composite`, the `construct` gate)
  are exempt from the "already tried" guardrail — you re-craft them every time
  you build, so they are never treated as spent research.

## [3.1.6] - 2026-09-07

### Fixed
- Autoplay no longer loops on `combine`. The brain would resubmit the same
  handful of ingredient sets dozens of times ("an uninvented combination for
  inventor points") when they were long-since known and minting nothing.
  - `AutoPlayer` now refreshes `GET /rules` every 90s (was fetched once per
    process) and merges the signature of any `combine` that APPLIES into the
    known set immediately.
  - A `combine` whose sorted signature is world-known, or already submitted by
    this agent this run, is dropped before it is sent and replaced with a
    productive fallback (sell a raw surplus) or a skipped turn.
  - Every submitted `combine` signature is recorded durably
    (`StateStore::recordCombineSignature()` / `getTriedCombineSignatures()`),
    so the "already submitted" list the brain sees is the whole run, not just
    the last eight turns.
  - The prompt tells the brain a `combine` that merely APPLIED still scores
    nothing unless `inventor_points` rose.

## [3.1.5] - 2026-09-07

### Fixed
- A queued intent whose id has aged out of the world's retention window no
  longer gets re-polled every autoplay turn. `IntentRepository::getIntentStatus()`
  maps a `404`/`410` to a terminal `gone` status instead of rejecting, and the
  autoplay loop drops the stored `queued_intent` once its outcome is settled
  (`applied` / `rejected` / `gone`) via the new
  `StateStore::clearQueuedIntent()`. This also stops the HTTP layer logging the
  same failed `GET /intent/{id}` with a stack trace on a loop.
- The autoplay loop in `bot.php` de-dupes its warnings: a persistent fault
  (brain host unreachable, API down) is logged once and then at most once every
  five minutes, instead of on every tick.

## [3.1.4] - 2026-09-07

### Fixed
- `require-dev` pins `symfony/console` to `^7.4`. `phpacker/phpacker` needs
  Symfony 7, but the DiscordPHP dev-master graph had floated it to 8.x, so a
  fresh `composer install` could not add phpacker. The other family bots got
  the same pin.

## [3.1.3] - 2026-09-07

### Fixed
- The packed binaries now run from wherever they land. `bot.php` /
  `autoplay.php` resolve `vendor/`, `.env` and `var/` by walking up from the
  real executable path (then the working directory), so a phpacker binary at
  `bin/build/<name>/<platform>/` works when double-clicked or launched from a
  shortcut with any working directory — previously it died with "Composer
  autoloader not found".

### Security
- `bin/build` is gitignored **and** `export-ignore`d in `.gitattributes`. A
  packed binary can be decompiled to recover whatever environment it ran with;
  it must never be committed or shipped in a dist archive.

## [3.1.2] - 2026-09-07

### Added
- PHPacker builds both entry points. `composer phpacker` now runs
  `phpacker:bot` (`bot.php` → `bin/build/bot`) and `phpacker:autoplay`
  (`autoplay.php` → `bin/build/autoplay`); root `phpacker.json` / `phpacker.ini`
  hold the shared build config (all platforms, PHP 8.4, `memory_limit=-1`).
- `bin/build` is gitignored; the phpacker config is `export-ignore`d from the
  dist archive.

## [3.1.1] - 2026-09-07

### Changed
- Brain: `plant` no longer stacks new trees — the NHA engine now tops up the
  most-drained tree on the cell (cap 22) and rejects the intent when they are
  all full. The `Playbook` anti-patterns, the `plant` verb hint, and the
  `/plant` help / docblocks say so, so the autoplay brain stops chop→plant
  looping on a full cell.

## [3.1.0] - 2026-09-07

### Added
- The rich Components V2 panels (the observation panel, `/help`, and the
  per-user control panel) now carry a subtle footer linking the source repo
  and GitHub Sponsors. One-line command replies are unchanged.
- `HelperTrait::GITHUB` / `HelperTrait::SPONSOR` constants and
  `HelperTrait::attributionComponents()` for reuse.
- `composer.json` now declares `"php": "^8.3"`.

## [3.0.1] - 2026-09-07

### Fixed
- Test harness only, no library or runtime change: the pure-unit HTTP and
  Parts tests no longer extend the integration base class (which opened a live
  Discord connection), `openapi.json` is tracked so the schema-drift test can
  run in CI, and `NHASingleton` coerces an unset `NHA_TOKEN` to a string.
- Added `.github/workflows/ci.yml` (lint, PHPUnit, php-cs-fixer on PHP
  8.3 / 8.4).

## [3.0.0] - 2026-09-07

First tagged release. Targets NHA world API **v3** (`openapi.json`
`info.version` `3.0`).

### Added
- Full NHA client on top of DiscordPHP: `NHA` (`MessageCommandClient` subclass),
  an async `NHA\Http` transport with rate-limit buckets, and `NHA\Http\Endpoint`
  route constants covering every non-asset path in the API.
- Typed read repositories for every board (`world`, `market`, `roster`,
  `contracts`, `relations`, history/meta boards, Expansion-era boards, …),
  resolving `NHA\Parts\*` models that preserve undeclared response keys.
- `NHA\VerbsTrait` — one typed wrapper per documented agent verb, all through
  `POST /intent`.
- `NHA\Commands` — framework-agnostic handlers shared by chat commands, slash
  commands and message components, plus the `AgentObservation` Components V2
  panel with context-aware action buttons.
- `NHA\StateStore` — atomic JSON persistence for the default agent id/token, the
  per-Discord-user identity map, last-known position, and the autoplay flag.
- Optional LLM autoplay: `OllamaClient` (native and OpenAI-compatible), a
  `Playbook` strategy, `AgentBrain` (observation digest + strict-JSON decision),
  `AutoPlayer` (one observe → decide → act turn), and a headless `autoplay.php`
  runner with a restart supervisor.
- Configurable world base URL: `NHA_BASE_URL` env / `nha_base_url` client option,
  falling back to `NHA\Http\Http::BASE_URL`.
- Contributor docs: `AGENTS.md` and specialist playbooks under `.agents/skills/`.

### Fixed
- Autoplay lease is a real compare-and-swap under an OS file lock, with a TTL
  derived from the turn interval and released on a clean `autoplay.php`
  shutdown; two runners can no longer submit two intents per interval from one
  token.
- The lease re-read no longer adopts an empty or truncated `state.json`, which
  could otherwise persist a file holding only the lease and drop the agent
  token (the NHA server issues it once).
- The brain digest no longer replays the previous turn's free-text `reason`
  back to the model; only the verb, args and the server's outcome carry
  forward, so a stale self-assertion ("I have 9 wood") can't loop.
- Agent registration never produces a bare `user-` name; names are clamped to
  the NHA 1–24 character limit.
- Agent tokens are scrubbed from logged `422` response bodies.

[3.1.4]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.4
[3.1.3]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.3
[3.1.2]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.2
[3.1.1]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.1
[3.1.0]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.1.0
[3.0.1]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.0.1
[3.0.0]: https://github.com/discord-php/DiscordPHP-NHA/releases/tag/v3.0.0
