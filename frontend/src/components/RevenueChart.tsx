// 12-month revenue/expenses bar chart — Chart.js port of the canvas chart on
// the PHP admin dashboard: stacked green revenue vs. red (negative) expenses,
// index-mode tooltip with the per-type breakdown and a Net footer, axis
// labels re-colored with the theme.

import {
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Legend,
  LinearScale,
  Tooltip,
} from 'chart.js';
import { Bar } from 'react-chartjs-2';
import { useTheme } from '../useTheme';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const REVENUE_LINES: [string, string][] = [
  ['monthly_tuition', 'Tuition'],
  ['registration', 'Registration'],
  ['belt_test', 'Belt Tests'],
  ['slc_training', 'SLC Training'],
  ['seminar', 'Seminar'],
  ['donations', 'Donations'],
  ['other', 'Other'],
];

const EXPENSE_LINES: [string, string][] = [
  ['exp_rent', 'Rent'],
  ['exp_equipment', 'Equipment'],
  ['exp_utilities', 'Utilities'],
  ['exp_supplies', 'Supplies'],
  ['exp_other', 'Other'],
];

export default function RevenueChart({
  labels,
  data,
}: {
  labels: string[];
  data: Record<string, number[]>;
}) {
  const theme = useTheme();
  const textColor = theme === 'dark' ? '#ffffff' : '#212529';

  const breakdown = (lines: [string, string][], i: number): string[] =>
    lines
      .filter(([key]) => (data[key]?.[i] ?? 0) > 0)
      .map(([key, label]) => ` ${label}: $${(data[key]?.[i] ?? 0).toFixed(2)}`);

  return (
    <Bar
      id="revenueChart"
      height={80}
      data={{
        labels,
        datasets: [
          {
            label: 'Revenue',
            data: data.revenue ?? [],
            backgroundColor: 'rgba(25,135,84,0.75)',
            stack: 'a',
          },
          {
            label: 'Expenses',
            data: data.expenses ?? [],
            backgroundColor: 'rgba(220,53,69,0.75)',
            stack: 'b',
          },
        ],
      }}
      options={{
        responsive: true,
        plugins: {
          legend: { position: 'top', labels: { color: textColor } },
          tooltip: {
            mode: 'index',
            callbacks: {
              label: (ctx) => {
                if (ctx.raw === 0) return '';
                const i = ctx.dataIndex;
                if (ctx.dataset.label === 'Revenue') return breakdown(REVENUE_LINES, i);
                if (ctx.dataset.label === 'Expenses') return breakdown(EXPENSE_LINES, i);
                return '';
              },
              footer: (items) => {
                const net = items.reduce((s, item) => s + (item.raw as number), 0);
                const sign = net < 0 ? '-' : '';
                return `Net: ${sign}$${Math.abs(net).toFixed(2)}`;
              },
            },
          },
        },
        scales: {
          x: { stacked: true, ticks: { color: textColor } },
          y: {
            stacked: true,
            ticks: { color: textColor, callback: (v) => '$' + Math.abs(Number(v)) },
          },
        },
      }}
    />
  );
}
