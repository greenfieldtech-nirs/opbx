import * as ort from 'onnxruntime-node';

export class OnnxModel {
    private session: ort.InferenceSession | null = null;

    constructor(private modelPath: string) {}

    async load(): Promise<void> {
        this.session = await ort.InferenceSession.create(this.modelPath);
    }

    isLoaded(): boolean {
        return this.session !== null;
    }

    async predict(mfccFeatures: number[]): Promise<number> {
        if (!this.session) {
            throw new Error('ONNX model not loaded');
        }

        const inputTensor = new ort.Tensor('float32', new Float32Array(mfccFeatures), [1, mfccFeatures.length]);
        // Explicitly request only the tensor output to avoid sequence/map type issues
        const results = await this.session.run({ mfcc_features: inputTensor }, ['output_label']);
        const output = results.output_label;
        const data = output.data as BigInt64Array | Int32Array | Float32Array;
        return Number(data[0]);
    }
}
