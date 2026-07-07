import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Phone, Users, PhoneCall, PhoneOff, Clock, Target, TrendingUp, LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

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

interface KpiConfig {
  label: string;
  value: number | string;
  icon: LucideIcon;
  color: string;
  bgColor: string;
}

export function KpiCards({ kpis }: KpiCardsProps) {
  const cards: KpiConfig[] = [
    {
      label: 'Total Calls',
      value: kpis.total_calls,
      icon: Phone,
      color: 'text-blue-600',
      bgColor: 'bg-blue-100',
    },
    {
      label: 'Unique Callers',
      value: kpis.unique_callers,
      icon: Users,
      color: 'text-purple-600',
      bgColor: 'bg-purple-100',
    },
    {
      label: 'Answered',
      value: kpis.answered_calls,
      icon: PhoneCall,
      color: 'text-green-600',
      bgColor: 'bg-green-100',
    },
    {
      label: 'Missed',
      value: kpis.missed_calls,
      icon: PhoneOff,
      color: 'text-orange-600',
      bgColor: 'bg-orange-100',
    },
    {
      label: 'Avg Duration (s)',
      value: kpis.average_duration,
      icon: Clock,
      color: 'text-amber-600',
      bgColor: 'bg-amber-100',
    },
    {
      label: 'Conversions',
      value: kpis.conversions,
      icon: Target,
      color: 'text-emerald-600',
      bgColor: 'bg-emerald-100',
    },
    {
      label: 'Conversion Rate',
      value: `${(kpis.conversion_rate * 100).toFixed(1)}%`,
      icon: TrendingUp,
      color: 'text-cyan-600',
      bgColor: 'bg-cyan-100',
    },
  ];

  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
      {cards.map((card) => {
        const Icon = card.icon;
        return (
          <Card key={card.label} className="hover:shadow-md transition-shadow">
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">{card.label}</CardTitle>
              <div className={cn('p-2 rounded-lg', card.bgColor)}>
                <Icon className={cn('h-5 w-5', card.color)} />
              </div>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{card.value}</div>
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
