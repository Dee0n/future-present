-- TES (2026-10-04): my NPC actions are offered to followers too. A guard who once followed the
-- player counts as a follower for CHIM, and Arrest_Person / Fine_Person / Sell_House / Furnish_House
-- (available_to_followers = false) silently disappeared from his list.
UPDATE public.core_action SET available_to_followers = true, updated_at = now()
WHERE code_name IN ('ArrestNPC', 'FineNPC', 'SellHouse', 'FurnishHouse');
