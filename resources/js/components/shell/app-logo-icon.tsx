import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg">
            <rect width="60" height="60" rx="15.45" fill="#363FF9" />
            <g fill="#fff">
                <rect x="14.5" y="14" width="9" height="8.67" rx="2.6" />
                <rect x="14.5" y="25.67" width="9" height="8.67" rx="2.6" />
                <rect x="14.5" y="37.33" width="9" height="8.67" rx="2.6" />
                <path d="M26.5 14h3.5c9.1 0 15.5 6.6 15.5 16s-6.4 16-15.5 16h-3.5v-8h3.5c4.3 0 7.3-3.3 7.3-8s-3-8-7.3-8h-3.5V14Z" />
            </g>
        </svg>
    );
}
