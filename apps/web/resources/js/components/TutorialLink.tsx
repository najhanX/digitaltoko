import { BookOpen } from 'lucide-react';

import { tutorialForPath } from './tutorialRoutes';

export default function TutorialLink({path,tab}:{path:string;tab?:string|null}) {
    return <a className="button secondary small tutorial-menu-link" href={'/panduan/'+tutorialForPath(path,tab)} target="_blank" rel="noopener noreferrer"><BookOpen size={17}/>Panduan<span className="sr-only"> (tab baru)</span></a>;
}
