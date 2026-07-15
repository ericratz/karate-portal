// Last-12-months attendance bar chart — Chart.js via react-chartjs-2,
// matching the canvas chart on the PHP dashboards: green bars, purple on
// months where a new belt was earned, theme-aware axes.

import {
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  LinearScale,
  Tooltip,
} from 'chart.js';
import { Bar } from 'react-chartjs-2';
import type { AttendanceChartMonth } from '../api/types';
import { useTheme } from '../useTheme';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip);

export default function AttendanceChart({ months }: { months: AttendanceChartMonth[] }) {
  const theme = useTheme();
  const grid = theme === 'dark' ? 'rgba(255,255,255,.12)' : 'rgba(0,0,0,.2)';
  const label = theme === 'dark' ? '#dee2e6' : '#000';

  return (
    <Bar
      id="attChart"
      data={{
        labels: months.map((m) => m.label),
        datasets: [
          {
            data: months.map((m) => m.count),
            backgroundColor: months.map((m) => (m.ranks ? '#6f42c1' : '#198754')),
            borderRadius: 4,
          },
        ],
      }}
      options={{
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (ctx) => `Classes: ${ctx.parsed.y}`,
              afterLabel: (ctx) => {
                const ranks = months[ctx.dataIndex]?.ranks;
                return ranks ? `Belt: ${ranks.join(', ')}` : '';
              },
            },
          },
        },
        scales: {
          x: { ticks: { color: label }, grid: { color: grid } },
          y: { beginAtZero: true, ticks: { stepSize: 1, color: label }, grid: { color: grid } },
        },
      }}
    />
  );
}
