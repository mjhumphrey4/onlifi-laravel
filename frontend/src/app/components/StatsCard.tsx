import { LucideIcon } from 'lucide-react';

interface StatsCardProps {
  title: string;
  value: string;
  icon: LucideIcon;
  trend?: {
    value: string;
    isPositive: boolean;
  };
}

export function StatsCard({ title, value, icon: Icon, trend }: StatsCardProps) {
  return (
    <div className="bg-card border border-border rounded-lg p-4 sm:p-6 hover:border-primary/50 transition-colors">
      <div className="flex items-start justify-between">
        <div className="flex-1">
          <p className="text-xs sm:text-sm text-muted-foreground mb-1">{title}</p>
          <h3 className="text-2xl sm:text-3xl text-card-foreground mb-2">{value}</h3>
          {trend && (
            <p className={`text-xs sm:text-sm ${trend.isPositive ? 'text-primary' : 'text-destructive'}`}>
              {trend.isPositive ? '↑' : '↓'} {trend.value}
            </p>
          )}
        </div>
        <div className="w-10 h-10 sm:w-12 sm:h-12 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
          <Icon className="w-5 h-5 sm:w-6 sm:h-6 text-primary" />
        </div>
      </div>
    </div>
  );
}