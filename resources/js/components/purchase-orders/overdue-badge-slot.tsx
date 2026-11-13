/**
 * RESERVED SPOT for the overdue badge (step 8.3).
 *
 * It is used next to the due date in the PO list and on the PO detail page.
 * Nothing is computed or shown yet. In 8.3 this component will receive what it
 * needs (due_date, DeliveryTiming) and render the badge. Because it is already
 * placed in both screens, 8.3 only has to change this one file.
 */
export default function OverdueBadgeSlot() {
    return <span data-slot="overdue-badge" className="ml-2 inline-block" />;
}