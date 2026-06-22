import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
} from 'recharts';

interface CallsChartProps {
  data: Array<{ date_key: string; calls: number; conversions: number }>;
}

export function CallsChart({ data }: CallsChartProps) {
  return (
    <div className="h-[300px] w-full">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data}>
          <CartesianGrid strokeDasharray="3 3" />
          <XAxis dataKey="date_key" />
          <YAxis />
          <Tooltip />
          <Legend />
          <Line type="monotone" dataKey="calls" stroke="#2563eb" name="Calls" />
          <Line type="monotone" dataKey="conversions" stroke="#16a34a" name="Conversions" />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
