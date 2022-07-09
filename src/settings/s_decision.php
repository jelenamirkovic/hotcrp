<?php
// settings/s_decision.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Decision_Setting {
    public $id;
    /** @var string */
    public $name;
    /** @var 'accept'|'reject' */
    public $category;

    function __construct($id, $name, $category) {
        $this->id = $id;
        $this->name = $name;
        $this->category = $category;
    }
}

class Decision_SettingParser extends SettingParser {
    function set_oldv(Si $si, SettingValues $sv) {
        assert($si->name0 === "decision/" && $si->name2 === "");
        $sv->set_oldv($si, new Decision_Setting(null, "", "accept"));
    }

    function prepare_oblist(Si $si, SettingValues $sv) {
        $m = [];
        foreach ($sv->conf->decision_map() as $did => $dname) {
            if ($did !== 0) {
                $m[] = new Decision_Setting($did, $dname, $did > 0 ? "accept" : "reject");
            }
        }
        $sv->append_oblist("decision", $m, "name");
    }

    /** @param int|'$' $ctr
     * @param array<int> $countmap */
    static private function print_decrow(SettingValues $sv, $ctr, $countmap) {
        $did = $sv->vstr("decision/{$ctr}/id");
        $isnew = $did === "" || $did === "new" || $did === "\$";
        $count = $countmap[$did] ?? 0;
        $editable = $sv->editable("decision");
        echo '<div id="decision/', $ctr, '" class="has-fold foldo settings-decision',
            $isnew ? ' is-new' : '', '"><div class="entryi">',
            $sv->feedback_at("decision/{$ctr}/name"),
            $sv->feedback_at("decision/{$ctr}/category"),
            Ht::hidden("decision/{$ctr}/id", $isnew ? "new" : $did, ["data-default-value" => $isnew ? "" : null]),
            $sv->entry("decision/{$ctr}/name", ["data-exists-count" => $count, "class" => $isnew ? "uii js-settings-decision-new-name" : ""]);
        if ($sv->reqstr("decision/{$ctr}/delete")) {
            echo Ht::hidden("decision/{$ctr}/delete", "1", ["data-default-value" => ""]);
        }
        Icons::stash_defs("trash");
        echo Ht::unstash();
        if ($editable) {
            echo Ht::button(Icons::ui_use("trash"), ["class" => "fx ui js-settings-decision-delete ml-2 need-tooltip", "name" => "decision/{$ctr}/deleter", "aria-label" => "Delete decision", "tabindex" => "-1"]);
        }
        echo '<span class="ml-2 d-inline-block fx">';
        $class = $sv->vstr("decision/{$ctr}/category");
        if ($isnew) {
            echo Ht::select("decision/{$ctr}/category",
                    ["accept" => "Accept category", "reject" => "Reject category"], $class,
                    $sv->sjs("decision/{$ctr}/category", ["data-default-value" => "accept"]));
        } else {
            echo $class === "accept" ? "<span class=\"pstat_decyes\">Accept</span> category" : "<span class=\"pstat_decno\">Reject</span> category";
            if ($count) {
                echo ", ", plural($count, "submission");
            }
        }
        if ($sv->has_error_at("decision/{$ctr}/category")) {
            echo '<label class="d-inline-block checki ml-2"><span class="checkc">',
                Ht::checkbox("decision/{$ctr}/name_force", 1, false),
                '</span><span class="is-error">Confirm</span></label>';
        }
        echo "</span></div></div>";
    }

    static function print(SettingValues $sv) {
        // count papers per decision
        $decs_pcount = [];
        $result = $sv->conf->qe_raw("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
        while (($row = $result->fetch_row())) {
            $decs_pcount[(int) $row[0]] = (int) $row[1];
        }
        Dbl::free($result);

        echo Ht::hidden("has_decision", 1),
            '<div id="settings-decision-types">';
        foreach ($sv->oblist_keys("decision") as $ctr) {
            self::print_decrow($sv, $ctr, $decs_pcount);
        }
        echo '</div>';
        foreach ($sv->use_req() ? $sv->oblist_keys("decision") : [] as $ctr) {
            if ($sv->reqstr("decision/{$ctr}/delete"))
                echo Ht::unstash_script("\$(\"#settingsform\")[0].elements[\"decision/{$ctr}/deleter\"].click()");
        }
        echo '<div id="settings-decision-type-notes" class="hidden">',
            '<div class="hint">Examples: “Accepted as short paper”, “Early reject”</div></div>';
        if ($sv->editable("decision")) {
            echo '<template id="settings-new-decision-type" class="hidden">';
            self::print_decrow($sv, '$', $decs_pcount);
            echo '</template><div class="mg">',
                Ht::button("Add decision type", ["class" => "ui js-settings-decision-add"]),
                '</div>';
        }
    }

    /** @param SettingValues $sv
     * @param object $dsr
     * @param int $ctr */
    private function _check_req_name($sv, $dsr, $ctr) {
        if ($dsr->id === null || $dsr->name !== $sv->conf->decision_name($dsr->id)) {
            if (($error = Conf::decision_name_error($dsr->name))
                && !$sv->has_error_at("decision/{$ctr}/name")) {
                $sv->error_at("decision/{$ctr}/name", "<0>{$error}");
            }
            if (!$sv->reqstr("decision/{$ctr}/name_force")
                && stripos($dsr->name, $dsr->category === "accept" ? "reject" : "accept") !== false) {
                $n1 = $dsr->category === "accept" ? "An Accept" : "A Reject";
                $n2 = $dsr->category === "accept" ? "reject" : "accept";
                $sv->error_at("decision/{$ctr}/name", "<0>{$n1}-category decision has “{$n2}” in its name");
                $sv->inform_at("decision/{$ctr}/name", "<0>Either change the decision name or category or check the “Confirm” box to save anyway.");
                $sv->error_at("decision/{$ctr}/category");
            }
        }
        $sv->error_if_duplicate_member("decision", $ctr, "name", "Decision name");
    }

    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name !== "decision") {
            return false;
        }

        $djs = [];
        $hasid = [];
        foreach ($sv->oblist_nondeleted_keys("decision") as $ctr) {
            $dsr = $sv->object_newv("decision/{$ctr}");
            $this->_check_req_name($sv, $dsr, $ctr);
            $djs[] = $dsr;
            $hasid[$dsr->id ?? ""] = true;
        }

        // name reuse, new ids
        foreach ($djs as $dj) {
            if ($dj->id === null) {
                $idstep = $dj->id = $dj->category === "accept" ? 1 : -1;
                while (isset($hasid[$dj->id])) {
                    $dj->id += $idstep;
                }
                $hasid[$dj->id] = true;
            }
        }

        // sort and save
        $collator = $sv->conf->collator();
        usort($djs, function ($a, $b) use ($collator) {
            if ($a->category !== $b->category) {
                return $a->category === "accept" ? -1 : 1;
            } else {
                return $collator->compare($a->name, $b->name);
            }
        });

        $dm = [];
        foreach ($djs as $dj) {
            $dm[$dj->id] = $dj->name;
        }
        $tx = json_encode_db($dm);

        $olddm = $sv->conf->decision_map();
        unset($olddm[0]);
        if ($tx !== json_encode_db($olddm)) {
            $sv->save("outcome_map", $tx);
            $sv->request_write_lock("Paper");
            $sv->request_store_value($si);
        }
        return true;
    }

    function store_value(Si $si, SettingValues $sv) {
        $curmap = $sv->conf->decision_map();
        $newmap = json_decode($sv->newv("outcome_map"), true);
        $newmap[0] = "Unspecified";
        $dels = array_diff_key($curmap, $newmap);
        if (!empty($dels)
            && ($pids = Dbl::fetch_first_columns($sv->conf->dblink, "select paperId from Paper where outcome?a", array_keys($dels)))) {
            $sv->conf->qe("update Paper set outcome=0 where outcome?a", array_keys($dels));
            $sv->user->log_activity("Set decision: Unspecified", $pids);
        }
    }
}
