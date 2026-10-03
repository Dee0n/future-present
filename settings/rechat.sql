-- TES (2026-10-04): NPCs talk less among themselves after the player's line. Live 02:55-02:57: a
-- guard given an order spent the next minute agreeing with another guard ("Да, Браксек, я знаю…")
-- while the player waited. Was 50. Rollback: UPDATE conf_opts SET value = '50' WHERE id = 'RECHAT_P';
UPDATE public.conf_opts SET value = '25' WHERE id = 'RECHAT_P';
