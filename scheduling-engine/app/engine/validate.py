"""
PART 0 ng algo: validation. Walang solver dito.

check_size       -> PAYLOAD_TOO_LARGE
check_candidate  -> INVALID_CANDIDATE
check_state      -> INVALID_STATE
Bawat function ay nagbabalik ng reason (str) kung may mali, o None kung ayos.
"""
from typing import Optional

from .availability import merge_intervals, fits, net_availability
from .types import (Booking, Config, DURATION, ADMIN, PC,
                    INVALID_CANDIDATE, INVALID_STATE, PAYLOAD_TOO_LARGE)


def check_size(n_active: int, cfg: Config) -> Optional[str]:
    """|B| + 1 <= N_max"""
    if n_active + 1 > cfg.n_max:
        return PAYLOAD_TOO_LARGE
    return None


def check_candidate(c: Booking, providers: dict, t_run: int) -> Optional[str]:
    """providers = {provider_id: Provider}"""
    # kulang / maling field
    if not c.id or c.service not in DURATION:
        return INVALID_CANDIDATE
    if not isinstance(c.start, int) or not isinstance(c.end, int):
        return INVALID_CANDIDATE

    # Use the service's configured duration when supplied by the booking app.
    duration = c.duration_minutes if c.duration_minutes is not None else DURATION[c.service]
    if duration <= 0 or c.end != c.start + duration:
        return INVALID_CANDIDATE

    # Candidate start time is in the past.
    if c.start < t_run:
        return INVALID_CANDIDATE

    # PC: a pinned provider eligible for PC is required.
    if c.service == PC:
        p = providers.get(c.pinned)
        if p is None or PC not in p.eligible:
            return INVALID_CANDIDATE
    else:
        # ABA/AC: must NOT be pinned (the engine selects the Admin).
        if c.pinned is not None:
            return INVALID_CANDIDATE

    return None


def _has_overlap(intervals) -> bool:
    """True kung may dalawang [s, f) na nag-ooverlap."""
    ordered = sorted(intervals)
    for (s1, f1), (s2, f2) in zip(ordered, ordered[1:]):
        if s2 < f1:
            return True
    return False


def check_state(active: list, providers: list) -> Optional[str]:
    """
    Consistent ba ang kasalukuyang assignment? (C3, C4, C6)
    Kapag hindi -> INVALID_STATE.
    """
    pmap = {p.id: p for p in providers}
    admin_ids = {p.id for p in providers if p.role == ADMIN}

    pinned = [b for b in active if b.pinned is not None]
    unpinned = [b for b in active if b.pinned is None]

    # --- pinned (PC) ---
    by_provider = {}
    for b in pinned:
        p = pmap.get(b.pinned)
        if p is None or b.service not in p.eligible:
            return INVALID_STATE
        # C6: within the provider's RAW availability.
        if not fits(b.start, b.end, merge_intervals(p.availability)):
            return INVALID_STATE
        by_provider.setdefault(b.pinned, []).append((b.start, b.end))
    # C6: no two pinned bookings for the same provider may overlap.
    for intervals in by_provider.values():
        if _has_overlap(intervals):
            return INVALID_STATE

    # --- unpinned (ABA/AC) ---
    net = net_availability(providers, pinned)
    by_admin = {}
    for b in unpinned:
        if b.kappa not in admin_ids:                       # missing or not an Admin
            return INVALID_STATE
        # C3: within the Admin's NET availability (after subtracting their pinned PC booking).
        if not fits(b.start, b.end, net[b.kappa]):
            return INVALID_STATE
        by_admin.setdefault(b.kappa, []).append((b.start, b.end))
    # C4: no overlap per Admin.
    for intervals in by_admin.values():
        if _has_overlap(intervals):
            return INVALID_STATE

    return None
