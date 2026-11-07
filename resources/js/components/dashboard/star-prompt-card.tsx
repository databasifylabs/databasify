import { Star, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useRepositoryStars } from '@/hooks/use-repository-stars';
import { repositoryUrl } from '@/lib/repository';
import { runAction } from '@/lib/run-action';
import { destroy as dismissStarPrompt } from '@/routes/repository/star-prompt';

function dismiss(): void {
    runAction(dismissStarPrompt());
}

export default function StarPromptCard() {
    const stars = useRepositoryStars();

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-4">
                <div className="space-y-1.5">
                    <CardTitle>Enjoying Databasify?</CardTitle>
                    <CardDescription>
                        A star on GitHub helps other people find it.
                    </CardDescription>
                </div>
                <div className="flex items-center gap-2">
                    <Button asChild variant="outline" size="sm">
                        <a
                            href={repositoryUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            onClick={dismiss}
                        >
                            <Star className="fill-amber-400 text-amber-400" />
                            {stars
                                ? `Star on GitHub · ${stars}`
                                : 'Star on GitHub'}
                        </a>
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Dismiss"
                        onClick={dismiss}
                    >
                        <X />
                    </Button>
                </div>
            </CardHeader>
        </Card>
    );
}
