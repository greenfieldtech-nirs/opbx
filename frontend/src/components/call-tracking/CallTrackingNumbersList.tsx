interface Props {
  numbers: Array<{ id: number; phone_number?: string; friendly_name: string | null; status: string }>;
  isLoading: boolean;
  campaignId: string | number;
  canManage: boolean;
}

export function CallTrackingNumbersList({ numbers, isLoading }: Props) {
  if (isLoading) return <p className="text-muted-foreground">Loading numbers...</p>;
  if (numbers.length === 0) return <p className="text-muted-foreground">No tracking numbers assigned.</p>;
  return (
    <div className="space-y-2">
      {numbers.map((n) => (
        <div key={n.id} className="border rounded p-3 flex justify-between items-center">
          <span>{n.phone_number || 'Unknown'} {n.friendly_name ? `(${n.friendly_name})` : ''}</span>
          <span className="text-sm text-muted-foreground capitalize">{n.status}</span>
        </div>
      ))}
    </div>
  );
}
