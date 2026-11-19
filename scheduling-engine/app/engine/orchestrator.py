"""
CheckAndRecommend — ang buong algo: Part 0 (validate) -> Stage 1 -> Stage 2.
Ito ang tatawagin ng FastAPI endpoint.
"""
import time
from dataclasses import replace
from typing import Optional

from .alternatives import ordered_candidates
from .assignment_ilp import stage1
from .types import (Booking, Config, HistoryPref, CheckResult, Recommendation, DURATION,
                    COMPLETED, INVALID, FEASIBLE, NOT_FEASIBLE, NO_ALTERNATIVE, NO_HISTORY)
from .validate import check_size, check_candidate, check_state


def _ms(t0: float) -> float:
    return (time.perf_counter() - t0) * 1000.0


def check_and_recommend(c: Booking, active: list, providers: list,
                        t_run: int, cfg: Config,
                        history: Optional[HistoryPref] = None) -> CheckResult:
    t0 = time.perf_counter()
    pmap = {p.id: p for p in providers}

    # ---------- PART 0: validate in order; stop at the first error ----------
    reason = check_size(len(active), cfg)
    if reason is None:
        reason = check_candidate(c, pmap, t_run)
    if reason is None:
        reason = check_state(active, providers)
    if reason is not None:
        return CheckResult(INVALID, invalid_reason=reason, runtime_ms=_ms(t0))

    # ---------- STAGE 1 ----------
    r = stage1(c, active, providers, cfg)
    if r.job_status != COMPLETED:                       # SOLVER_LIMIT / SOLVER_ERROR
        return CheckResult(r.job_status, stage1=r, runtime_ms=_ms(t0))
    if r.decision == FEASIBLE:
        return CheckResult(COMPLETED, FEASIBLE, stage1=r, recommendations=[],
                           runtime_ms=_ms(t0))

    # ---------- STAGE 2: History-based alternative recommendation ----------
    # If there is no history, skip Stage 2 and return NO_HISTORY.
    if history is None:
        return CheckResult(COMPLETED, NOT_FEASIBLE, stage1=None,
                           recommendations=NO_HISTORY,
                           candidate_checks=0, runtime_ms=_ms(t0))

    recs, checks = [], 0
    duration = c.duration_minutes if c.duration_minutes is not None else DURATION[c.service]
    for tier, provider, start in ordered_candidates(c, history, t_run, cfg, providers):
        checks += 1
        c2 = replace(c, start=start, end=start + duration, pinned=provider)
        r2 = stage1(c2, active, providers, cfg)
        # For SOLVER_LIMIT / SOLVER_ERROR, skip the recommendation but still count the result.
        if r2.job_status == COMPLETED and r2.decision == FEASIBLE:
            recs.append(Recommendation(len(recs) + 1, tier, provider, start, c2.end))
        if len(recs) == cfg.k_rec or checks == cfg.max_checks:
            break

    return CheckResult(COMPLETED, NOT_FEASIBLE, stage1=None,
                       recommendations=recs if recs else NO_ALTERNATIVE,
                       candidate_checks=checks, runtime_ms=_ms(t0))
