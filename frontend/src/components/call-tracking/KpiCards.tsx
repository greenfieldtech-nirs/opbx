import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface KpiCardsProps {
  kpis: {
    total_calls: number;
    unique_callers: number;
    answered_calls: number;
    missed_calls: number;
    average_duration: number;
    conversions: number;
    conversion_rate: number;
  };
}

export function KpiCards({ kpis }: KpiCardsProps) {
  const cards = [
    { label: 'Total Calls', value: kpis.total_calls },
    { label: 'Unique Callers', value: kpis.unique_callers },
    { label: 'Answered', value: kpis.answered_calls },
    { label: 'Missed', value: kpis.missed_calls },
    { label: 'Avg Duration (s)', value: kpis.average_duration },
    { label: 'Conversions', value: kpis.conversions },
    { label: 'Conversion Rate', value: `${(kpis.conversion_rate * 100).toFixed(1)}%` },
  ];

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      {cards.map((card) => (
        <Card key={card.label}>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">{card.label}</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{card.value}</div>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
